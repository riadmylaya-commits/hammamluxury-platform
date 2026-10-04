<?php

namespace App\Domain\Booking;

use App\Domain\Phone\PhoneNumber;
use App\Domain\Privacy\ContactMasker;
use App\Events\BookingCreated;
use App\Events\BookingExpired;
use App\Events\BookingStatusChanged;
use App\Models\ActivityLog;
use App\Models\Allocation;
use App\Models\Booking;
use App\Models\LedgerEntry;
use App\Models\Spa;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cycle de vie d'une réservation : intention → validation finale sous verrou → statuts →
 * libération des allocations → expiration des demandes en attente.
 */
class BookingService
{
    public function __construct(
        private CapacityEngine $engine,
        private QuoteBuilder $quotes,
        private IntentService $intents,
    ) {}

    /**
     * Étape 1 : devis + contrôle de disponibilité, puis intention signée.
     *
     * @param  array  $req  {treatment|participants, party, extras, date: Y-m-d, time: H:i}
     * @return array{intent: array, quote: array, start_at: string, end_at: string}
     */
    public function prepare(Spa $spa, array $req): array
    {
        $this->assertBookable($spa);
        $quote = $this->quotes->fromRequest($spa, $req);
        if (! $quote['ok']) {
            throw new BookingException('quote', implode(' ', $quote['errors']));
        }
        $start = $this->parseStart($req['date'] ?? '', $req['time'] ?? '');
        if ($start < CarbonImmutable::now()->addMinutes($spa->minLead())) {
            throw BookingException::make('too_soon', 'too_soon', ['minutes' => $spa->minLead()]);
        }
        $plan = $this->engine->check($spa, $start, $quote['items']);
        if (! $plan['ok']) {
            throw new BookingException('unavailable', implode(' ', $plan['errors']), 409);
        }
        $intent = $this->intents->create([
            'spa_id' => $spa->id,
            'items' => $quote['items'],
            'fingerprint' => $quote['fingerprint'],
            'total' => $quote['total'],
            'duration_min' => $quote['duration_min'],
            'start_at' => $start->format('Y-m-d H:i:s'),
            'locale' => app()->getLocale(),
            'rate' => $quote['rate'],
        ]);

        return ['intent' => $intent, 'quote' => $quote, 'start_at' => $start->toDateTimeString(), 'end_at' => $start->addMinutes($quote['duration_min'])->toDateTimeString()];
    }

    /**
     * Étape 2 : validation finale sous verrou. Le devis est recalculé côté serveur et comparé
     * à l'intention (prix ou durée modifiés entre-temps → refus), puis la capacité est
     * re-planifiée avant insertion atomique de la réservation, des participants et des allocations.
     */
    public function confirmIntent(Spa $spa, string $token, array $customer): Booking
    {
        $this->assertBookable($spa);
        $payload = $this->intents->resolve($token, $spa);
        $start = CarbonImmutable::parse($payload['start_at']);
        $participants = array_map(fn ($i) => ['participant_no' => $i['participant_no'], 'treatment_id' => $i['treatment'], 'party' => $i['party'], 'extras' => $i['extras']], $payload['items']);
        $quote = $this->quotes->build($spa, $participants, $payload['rate'] ?? 'standard');
        if (! $quote['ok']) {
            throw new BookingException('quote', implode(' ', $quote['errors']));
        }
        if ($quote['fingerprint'] !== $payload['fingerprint'] || (float) $quote['total'] !== (float) $payload['total']) {
            throw BookingException::make('price_changed', 'price_changed', [], 409);
        }

        $booking = $this->engine->withLock($spa, fn () => $this->insert($spa, $start, $quote, $customer, 'waiting', $payload['id'], $payload['locale'] ?? app()->getLocale()));
        $this->intents->consume($payload['id'], $booking->id);

        return $booking;
    }

    /**
     * Réservation directe (tests, saisie partenaire) : devis + plan sous verrou.
     *
     * @param  array  $items  [{treatment, party, extras, participant_no}]
     */
    public function book(Spa $spa, CarbonImmutable $start, array $items, array $customer, string $status = 'waiting', string $rate = 'standard'): Booking
    {
        $participants = [];
        foreach (array_values($items) as $i => $item) {
            $participants[] = ['participant_no' => $item['participant_no'] ?? $i + 1, 'treatment' => $item['treatment'], 'party' => $item['party'] ?? 1, 'extras' => $item['extras'] ?? []];
        }
        $quote = $this->quotes->fromRequest($spa, ['participants' => $participants, 'rate' => $rate]);
        if (! $quote['ok']) {
            throw new BookingException('quote', implode(' ', $quote['errors']));
        }

        return $this->engine->withLock($spa, fn () => $this->insert($spa, $start, $quote, $customer, $status));
    }

    private function insert(Spa $spa, CarbonImmutable $start, array $quote, array $customer, string $status, ?string $intentId = null, ?string $locale = null): Booking
    {
        $plan = $this->engine->plan($spa, $this->engine->expandNeeds($spa, $start, $quote['items']));
        if (! $plan['ok']) {
            throw new BookingException('unavailable', implode(' ', $plan['errors']), 409);
        }
        $pct = $spa->partner->commissionPct();
        $commissionable = round((float) ($quote['commissionable'] ?? $quote['total']), 2);

        $booking = DB::transaction(function () use ($spa, $start, $quote, $customer, $status, $intentId, $locale, $plan, $pct, $commissionable) {
            $booking = Booking::create([
                'spa_id' => $spa->id,
                'user_id' => $customer['user_id'] ?? null,
                'status' => $status,
                'start_at' => $start,
                'end_at' => $start->addMinutes($quote['duration_min']),
                'party' => $quote['party'],
                'duration_min' => $quote['duration_min'],
                'total' => $quote['total'],
                'rate_type' => $quote['rate'] ?? 'standard',
                'cancellation_hours' => $quote['cancellation_hours'] ?? $spa->cancellation_hours,
                'standard_total' => $quote['standard_total'] ?? $quote['total'],
                'nr_discount_pct' => $quote['nr_discount_pct'] ?? null,
                'commissionable_amount' => $commissionable,
                'commission_pct' => $pct,
                'commission_amount' => round($commissionable * $pct / 100, 2),
                'currency' => $quote['currency'],
                'payment_status' => 'on_site',
                'first_name' => $customer['first_name'] ?? '',
                'last_name' => $customer['last_name'] ?? '',
                'email' => $customer['email'] ?? '',
                'phone' => PhoneNumber::normalize($customer['phone'] ?? null) ?? ($customer['phone'] ?? ''),
                'hotel' => $customer['hotel'] ?? null,
                'note' => isset($customer['note']) && $customer['note'] !== '' ? ContactMasker::redact($customer['note']) : null,
                'locale' => $locale ?? app()->getLocale(),
                'quote' => $quote,
                'intent_id' => $intentId,
                'expires_at' => $status === 'waiting' ? $this->waitingDeadline() : null,
                'confirmed_at' => $status === 'confirmed' ? now() : null,
            ]);
            $participantIds = [];
            foreach ($quote['lines'] as $l) {
                $participantIds[$l['participant_no']] = $booking->participants()->create([
                    'spa_id' => $spa->id,
                    'participant_no' => $l['participant_no'],
                    'party' => $l['party'],
                    'treatment_id' => $l['treatment_id'],
                    'treatment_name' => $l['treatment_name'],
                    'extras' => $l['extras'],
                    'duration_min' => $l['duration_min'],
                    'price' => $l['price'],
                ])->id;
            }
            foreach ($plan['allocations'] as $a) {
                $booking->allocations()->create([
                    'spa_id' => $spa->id,
                    'resource_id' => $a['resource_id'],
                    'resource_type_id' => $a['resource_type_id'],
                    'treatment_id' => $a['treatment_id'],
                    'booking_participant_id' => $participantIds[$a['participant_no']] ?? null,
                    'start_at' => $a['start_at'],
                    'end_at' => $a['end_at'],
                    'party' => $a['party'],
                    'status' => 'active',
                ]);
            }
            $booking->log('created', 'client', ['status' => $status]);

            return $booking;
        });

        event(new BookingCreated($booking));

        return $booking;
    }

    /* ------------------------------------------------------------------ Transitions */

    public function accept(Booking $booking, string $actor = 'partner', ?string $note = null): Booking
    {
        if (! $booking->isWaiting()) {
            throw BookingException::make('status', 'not_waiting', [], 409);
        }

        $booking = $this->transition($booking, 'confirmed', $actor, ['confirmed_at' => now(), 'expires_at' => null, 'partner_note' => $note, 'contact_revealed_at' => now()]);
        $booking->log('contact:revealed', 'system', ['fields' => ['phone', 'whatsapp']]);

        return $booking;
    }

    public function decline(Booking $booking, string $actor = 'partner', ?string $note = null): Booking
    {
        if (! $booking->isWaiting()) {
            throw BookingException::make('status', 'not_waiting', [], 409);
        }

        return $this->transition($booking, 'declined', $actor, ['expires_at' => null, 'partner_note' => $note]);
    }

    /**
     * Annulation. Par le client : gratuite avant la limite figée sur la réservation (tarif standard) ; au-delà, ou en tarif
     * non remboursable, 100 % du montant reste dû et la commission est conservée (même mécanique que le no-show avec frais).
     * Par le partenaire (demande acceptée) ou l'administration : jamais de frais pour le client. Le créneau est toujours libéré.
     */
    public function cancel(Booking $booking, string $actor = 'client'): Booking
    {
        if (! $booking->isActive()) {
            throw BookingException::make('status', 'already_inactive', [], 409);
        }

        $fee = $actor === 'client' ? $booking->cancelFeeNow() : 0.0;
        $booking = $this->transition($booking, 'cancelled', $actor, ['cancelled_at' => now(), 'cancelled_by' => $actor, 'expires_at' => null, 'cancel_fee' => $fee > 0 ? $fee : null]);
        $kind = $fee > 0 ? ($booking->isNonRefundable() ? 'cancel:non_refundable' : 'cancel:late_fee') : 'cancel:free';
        $booking->log($kind, $actor, array_filter(['fee' => $fee, 'commission' => $fee > 0 ? $booking->commission_amount : 0, 'until' => $booking->freeCancellationUntil()?->toDateTimeString()]));

        // Une demande d'annulation encore ouverte n'a plus d'objet une fois la réservation annulée.
        $booking->cancellationRequests()->where('status', 'pending')
            ->update(['status' => 'closed', 'decided_at' => now(), 'decision_note' => 'booking_cancelled:'.$actor]);

        return $booking;
    }

    public function complete(Booking $booking, string $actor = 'partner'): Booking
    {
        if (! $booking->isConfirmed()) {
            throw BookingException::make('status', 'not_confirmed', [], 409);
        }

        return $this->transition($booking, 'completed', $actor);
    }

    /**
     * Client absent. Le partenaire ne peut déclarer qu'entre l'heure du rendez-vous et NO_SHOW_WINDOW_HOURS après la fin
     * prévue ; l'administration peut corriger à tout moment après le rendez-vous, y compris une réservation déjà terminée.
     * Frais appliqués → le total reste dû et la commission HammamLuxury est conservée ; frais abandonnés → geste commercial, rien de dû.
     */
    public function noShow(Booking $booking, string $actor = 'partner', bool $applyFee = true, ?string $note = null): Booking
    {
        if ($actor === 'admin') {
            if (! in_array($booking->status, ['confirmed', 'completed'], true) || $booking->start_at->gt(now())) {
                throw BookingException::make('status', 'no_show_not_allowed', [], 409);
            }
        } elseif (! $booking->partnerNoShowWindowOpen()) {
            throw BookingException::make('status', $booking->isConfirmed() && $booking->start_at->isPast() ? 'no_show_window_closed' : 'no_show_not_allowed', [], 409);
        }

        $fee = $applyFee ? round((float) $booking->total, 2) : 0.0;
        $booking = $this->transition($booking, 'no_show', $actor, [
            'no_show_fee' => $fee,
            'no_show_fee_waived' => ! $applyFee,
            'no_show_at' => now(),
        ]);
        $booking->log($applyFee ? 'no_show:fee_applied' : 'no_show:fee_waived', $actor, array_filter(['fee' => $fee, 'commission' => $applyFee ? $booking->commission_amount : 0, 'note' => $note]));
        ActivityLog::record($applyFee ? 'booking.no_show_fee_applied' : 'booking.no_show_fee_waived', $booking, array_filter(['ref' => $booking->reference, 'fee' => $fee, 'note' => $note]), $actor);

        return $booking;
    }

    /** Réservation non honorée par l'établissement : incident sérieux, aucune commission, enregistré par l'administration seulement. */
    public function partnerNoShow(Booking $booking, string $actor = 'admin', ?string $note = null): Booking
    {
        if ($actor !== 'admin') {
            throw BookingException::make('actor', 'admin_only', [], 403);
        }
        if (! in_array($booking->status, ['confirmed', 'completed'], true) || $booking->start_at->gt(now())) {
            throw BookingException::make('status', 'no_show_not_allowed', [], 409);
        }

        $booking = $this->transition($booking, 'partner_no_show', $actor, ['no_show_at' => now()]);
        $booking->cancellationRequests()->where('status', 'pending')
            ->update(['status' => 'closed', 'decided_at' => now(), 'decision_note' => 'partner_no_show']);
        ActivityLog::record('booking.partner_no_show', $booking, array_filter(['ref' => $booking->reference, 'note' => $note]), $actor);

        return $booking;
    }

    /**
     * Déplace une réservation confirmée vers un nouveau créneau (même formule, même prix) : re-planification sous verrou
     * en excluant ses propres allocations, puis remplacement atomique des allocations.
     */
    public function reschedule(Booking $booking, CarbonImmutable $start, string $actor = 'admin'): Booking
    {
        if (! $booking->isConfirmed()) {
            throw BookingException::make('status', 'not_confirmed', [], 409);
        }
        $spa = $booking->spa;
        $quote = $booking->quote ?? [];
        if (empty($quote['items'])) {
            throw BookingException::make('quote', 'quote_missing', [], 409);
        }

        return $this->engine->withLock($spa, function () use ($booking, $spa, $start, $quote, $actor) {
            $plan = $this->engine->plan($spa, $this->engine->expandNeeds($spa, $start, $quote['items']), $booking->id);
            if (! $plan['ok']) {
                throw new BookingException('unavailable', implode(' ', $plan['errors']), 409);
            }
            $old = $booking->start_at;

            DB::transaction(function () use ($booking, $spa, $start, $plan, $actor, $old) {
                $this->release($booking);
                $participantIds = $booking->participants()->pluck('id', 'participant_no')->all();
                foreach ($plan['allocations'] as $a) {
                    $booking->allocations()->create([
                        'spa_id' => $spa->id,
                        'resource_id' => $a['resource_id'],
                        'resource_type_id' => $a['resource_type_id'],
                        'treatment_id' => $a['treatment_id'],
                        'booking_participant_id' => $participantIds[$a['participant_no']] ?? null,
                        'start_at' => $a['start_at'],
                        'end_at' => $a['end_at'],
                        'party' => $a['party'],
                        'status' => 'active',
                    ]);
                }
                $booking->update(['start_at' => $start, 'end_at' => $start->addMinutes($booking->duration_min)]);
                $booking->log('rescheduled', $actor, ['from' => $old->toDateTimeString(), 'to' => $start->toDateTimeString()]);
                ActivityLog::record('booking.rescheduled', $booking, ['ref' => $booking->reference, 'from' => $old->toDateTimeString(), 'to' => $start->toDateTimeString()], $actor);
            });

            return $booking->refresh();
        });
    }

    /** Passe en « terminée » les réservations confirmées dont l'heure de fin est dépassée (sans action du partenaire). */
    public function completePast(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $done = [];
        $due = Booking::where('status', 'confirmed')->where('end_at', '<', $now->subMinutes((int) config('hl.auto_complete_after_min', 60)))->get();
        foreach ($due as $booking) {
            $this->transition($booking, 'completed', 'system');
            $done[] = $booking->id;
        }

        return $done;
    }

    public function setPaymentStatus(Booking $booking, string $actor, string $status): Booking
    {
        if (! in_array($status, Booking::PAYMENT_STATUSES, true)) {
            throw BookingException::make('payment', 'invalid_payment_status');
        }
        $old = $booking->payment_status;
        $booking->update(['payment_status' => $status]);
        $booking->log('payment:'.$status, $actor, ['from' => $old]);

        return $booking;
    }

    private function transition(Booking $booking, string $status, string $actor, array $extra = []): Booking
    {
        $old = $booking->status;
        DB::transaction(function () use ($booking, $status, $actor, $extra, $old) {
            $booking->fill(['status' => $status] + $extra)->save();
            if (in_array($status, Booking::INACTIVE_STATUSES, true)) {
                $this->release($booking);
            }
            // Commission due : prestation réalisée, ou client absent avec frais facturés. Retirée si la prestation est requalifiée sans frais.
            $commissionDue = $status === 'completed'
                || ($status === 'no_show' && ! ($extra['no_show_fee_waived'] ?? false))
                || ($status === 'cancelled' && ($extra['cancel_fee'] ?? 0) > 0);
            if ($commissionDue) {
                LedgerEntry::firstOrCreate(
                    ['booking_id' => $booking->id, 'type' => 'commission'],
                    ['partner_id' => $booking->spa->partner_id, 'amount' => $booking->commission_amount, 'currency' => $booking->currency],
                );
            } elseif ($old === 'completed') {
                LedgerEntry::where('booking_id', $booking->id)->where('type', 'commission')->delete();
            }
            $booking->log($status, $actor, ['from' => $old]);
        });
        event(new BookingStatusChanged($booking, $old));

        return $booking;
    }

    /** Libère les allocations d'une réservation (idempotent). */
    public function release(Booking $booking): int
    {
        return Allocation::where('booking_id', $booking->id)->where('status', 'active')->update(['status' => 'released', 'updated_at' => now()]);
    }

    /* ------------------------------------------------------------------ Expiration */

    public function waitingDeadline(?CarbonImmutable $from = null): CarbonImmutable
    {
        return ($from ?? CarbonImmutable::now())->addMinutes((int) round(60 * (float) config('hl.waiting_ttl_hours')));
    }

    /**
     * Expire les demandes en attente dont l'échéance est dépassée. Idempotent : une réservation
     * déjà expirée n'est jamais retraitée ni renotifiée.
     *
     * @return int[] identifiants expirés lors de ce passage
     */
    public function expireWaiting(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $expired = [];
        $due = Booking::where('status', 'waiting')->whereNotNull('expires_at')->where('expires_at', '<=', $now)->get();
        foreach ($due as $booking) {
            $updated = Booking::where('id', $booking->id)->where('status', 'waiting')->update(['status' => 'expired', 'expiration_notified_at' => $now, 'updated_at' => $now]);
            if (! $updated) {
                continue;
            }
            $booking->refresh();
            $this->release($booking);
            $booking->log('expired', 'system');
            $expired[] = $booking->id;
            event(new BookingExpired($booking));
        }

        return $expired;
    }

    /* ------------------------------------------------------------------ Divers */

    public function assertBookable(Spa $spa): void
    {
        if (! $spa->isPublished() || ! $spa->partner?->isApproved()) {
            throw BookingException::make('spa', 'spa_not_bookable', [], 404);
        }
    }

    public function parseStart(string $date, string $time): CarbonImmutable
    {
        $dt = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && preg_match('/^\d{2}:\d{2}$/', $time)
            ? CarbonImmutable::createFromFormat('Y-m-d H:i', "$date $time")
            : null;
        if (! $dt || $dt->format('Y-m-d H:i') !== "$date $time") {
            throw BookingException::make('datetime', 'invalid_datetime');
        }

        return $dt->setSecond(0);
    }
}
