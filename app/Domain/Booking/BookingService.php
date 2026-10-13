<?php

namespace App\Domain\Booking;

use App\Domain\Phone\PhoneNumber;
use App\Domain\Privacy\ContactMasker;
use App\Events\BookingCreated;
use App\Events\BookingExpired;
use App\Events\BookingStatusChanged;
use App\Events\CancellationArbitrationRequired;
use App\Models\ActivityLog;
use App\Models\Allocation;
use App\Models\Booking;
use App\Models\BookingDecline;
use App\Models\CancellationRequest;
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
        private BookingMutation $mutations,
        private CommissionLedger $ledger,
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
        return $this->mutations->forSpa($spa, function (Spa $current) use ($token, $customer): Booking {
            $intentId = explode('.', $token, 2)[0];
            $existing = Booking::query()->where('intent_id', $intentId)->lockForUpdate()->first();
            if ($existing && $existing->intent_token_hash !== null) {
                if (! hash_equals($existing->intent_token_hash, hash('sha256', $token))) {
                    throw BookingException::make('intent_tampered', 'intent_invalid', [], 403);
                }
                if ((int) $existing->spa_id !== (int) $current->id) {
                    throw BookingException::make('intent_spa', 'intent_invalid', [], 403);
                }
                if (! $existing->intent_expires_at || $existing->intent_expires_at->lte(now())) {
                    throw BookingException::make('intent_expired', 'intent_expired', [], 410);
                }
                if (! $this->sameCustomer($existing, $customer)) {
                    throw BookingException::make('intent_replayed', 'intent_replayed', [], 409);
                }

                return $existing;
            }
            $payload = $this->intents->resolve($token, $current, allowConsumed: true);
            $existing = Booking::query()->where('intent_id', $payload['id'])->lockForUpdate()->first();
            if ($existing) {
                if ((int) $existing->spa_id !== (int) $current->id || ! $this->sameCustomer($existing, $customer)) {
                    throw BookingException::make('intent_replayed', 'intent_replayed', [], 409);
                }
                // La base est la source de vérité, même si la consommation du cache avait échoué.
                DB::afterCommit(fn () => $this->intents->consume($payload['id'], $existing->id));

                return $existing;
            }
            if (! empty($payload['consumed_booking_id'])) {
                throw BookingException::make('intent_replayed', 'intent_replayed', [], 409);
            }

            $this->assertBookable($current);
            $start = CarbonImmutable::parse($payload['start_at']);
            $this->assertFutureStart($current, $start, enforceLead: true);
            $participants = array_map(fn ($i) => ['participant_no' => $i['participant_no'], 'treatment_id' => $i['treatment'], 'party' => $i['party'], 'extras' => $i['extras']], $payload['items']);
            $quote = $this->quotes->build($current, $participants, $payload['rate'] ?? 'standard');
            if (! $quote['ok']) {
                throw BookingException::make('catalogue_changed', 'catalogue_changed', [], 409);
            }
            if ($quote['fingerprint'] !== $payload['fingerprint'] || (float) $quote['total'] !== (float) $payload['total']) {
                throw BookingException::make('price_changed', 'price_changed', [], 409);
            }

            $status = $current->instantBookingActive() ? 'confirmed' : 'waiting';
            $booking = $this->insert($current, $start, $quote, $customer, $status, $payload['id'], $payload['locale'] ?? app()->getLocale(), meta: [
                'intent_token_hash' => hash('sha256', $token),
                'intent_expires_at' => CarbonImmutable::createFromTimestamp($payload['expires_at'], config('app.timezone')),
            ]);
            DB::afterCommit(fn () => $this->intents->consume($payload['id'], $booking->id));

            return $booking;
        });
    }

    /**
     * Réservation directe (tests, saisie partenaire) : devis + plan sous verrou.
     *
     * @param  array  $items  [{treatment, party, extras, participant_no}]
     */
    public function book(Spa $spa, CarbonImmutable $start, array $items, array $customer, string $status = 'waiting', string $rate = 'standard', array $meta = [], string $actor = 'client'): Booking
    {
        return $this->mutations->forSpa($spa, function (Spa $current) use ($start, $items, $customer, $status, $rate, $meta, $actor): Booking {
            if (! in_array($status, ['waiting', 'confirmed'], true)) {
                throw BookingException::make('status', 'invalid', [], 422);
            }
            $participants = [];
            foreach (array_values($items) as $i => $item) {
                $participants[] = ['participant_no' => $item['participant_no'] ?? $i + 1, 'treatment' => $item['treatment'], 'party' => $item['party'] ?? 1, 'extras' => $item['extras'] ?? []];
            }
            $quote = $this->quotes->fromRequest($current, ['participants' => $participants, 'rate' => $rate]);
            if (! $quote['ok']) {
                throw new BookingException('quote', implode(' ', $quote['errors']));
            }

            return $this->insert($current, $start, $quote, $customer, $status, meta: $meta, actor: $actor);
        });
    }

    /**
     * Saisie partenaire d'une réservation reçue hors plateforme (téléphone, WhatsApp, réception…) :
     * confirmée d'emblée, même devis, même plan de capacité et même verrou qu'une réservation en ligne,
     * donc elle consomme exactement la même capacité. Pas de délai minimum (le client peut être déjà sur place),
     * mais jamais avant aujourd'hui ni en dehors des horaires d'ouverture.
     *
     * @param  array  $items  [{treatment, party, extras}]
     */
    public function bookOffline(Spa $spa, CarbonImmutable $start, array $items, array $customer, string $channel, ?int $userId = null): Booking
    {
        if (! in_array($channel, Booking::CHANNELS, true)) {
            throw new BookingException('channel', 'channel');
        }
        if ($start < CarbonImmutable::now()->startOfDay()) {
            throw BookingException::make('too_soon', 'past_date');
        }

        return $this->book($spa, $start, $items, $customer, 'confirmed', meta: ['source' => 'partner', 'channel' => $channel, 'created_by_user_id' => $userId], actor: 'partner');
    }

    private function insert(Spa $spa, CarbonImmutable $start, array $quote, array $customer, string $status, ?string $intentId = null, ?string $locale = null, array $meta = [], string $actor = 'client'): Booking
    {
        $plan = $this->engine->plan($spa, $this->needsForQuote($spa, $start, $quote));
        if (! $plan['ok']) {
            throw new BookingException('unavailable', implode(' ', $plan['errors']), 409);
        }
        $offline = ($meta['source'] ?? 'online') === 'partner';
        $pct = $offline ? 0.0 : $spa->partner->commissionPct();
        $commissionable = $offline ? 0.0 : round((float) ($quote['commissionable'] ?? $quote['total']), 2);

        $booking = DB::transaction(function () use ($spa, $start, $quote, $customer, $status, $intentId, $locale, $plan, $pct, $commissionable, $meta, $actor) {
            $booking = Booking::create($meta + [
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
                'expires_at' => $status === 'waiting' ? min($this->waitingDeadline(), $start) : null,
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
            $booking->log('created', $actor, array_filter(['status' => $status, 'channel' => $meta['channel'] ?? null]));

            return $booking;
        });

        DB::afterCommit(fn () => event(new BookingCreated($booking)));

        return $booking;
    }

    /* ------------------------------------------------------------------ Transitions */

    public function accept(Booking $booking, string $actor = 'partner', ?string $note = null): Booking
    {
        return $this->mutations->forBooking($booking, function (Booking $current) use ($actor, $note): Booking {
            if (! $current->isWaiting()) {
                throw BookingException::make('status', 'not_waiting', [], 409);
            }
            if ($current->expires_at && $current->expires_at->lte(now())) {
                throw BookingException::make('waiting_expired', 'waiting_expired', [], 409);
            }
            $this->assertFutureStart($current->spa, CarbonImmutable::instance($current->start_at));
            $this->assertAllocationsIntact($current);
            $this->transition($current, 'confirmed', $actor, ['confirmed_at' => now(), 'expires_at' => null, 'partner_note' => $note, 'contact_revealed_at' => now()]);
            $current->log('contact:revealed', 'system', ['fields' => ['phone', 'whatsapp']]);

            return $current;
        });
    }

    /**
     * Refus structuré d'une demande : motif obligatoire, explication interne facultative (jamais montrée au client).
     * Avant de libérer le créneau, on note si le moteur avait encore de la place pour cette demande (hors ses propres
     * allocations) : un refus « plus de place » alors que la capacité existait signale un planning non tenu à jour.
     */
    public function decline(Booking $booking, string $actor = 'partner', ?string $reason = null, ?string $note = null, ?int $userId = null): Booking
    {
        if (! in_array($reason, BookingDecline::REASONS, true)) {
            throw BookingException::make('decline_reason', 'reason_required', [], 422);
        }
        $note = trim((string) $note) !== '' ? trim($note) : null;
        if ($reason === 'other' && $note === null) {
            throw BookingException::make('decline_reason', 'note_required_other', [], 422);
        }

        return $this->mutations->forBooking($booking, function (Booking $booking) use ($actor, $reason, $note, $userId): Booking {
            $spa = $booking->spa;
            if (! $booking->isWaiting()) {
                throw BookingException::make('status', 'not_waiting', [], 409);
            }
            $items = $booking->quote['items'] ?? [];
            $engineAvailable = null;
            if ($items !== []) {
                $engineAvailable = (bool) ($this->engine->plan($spa, $this->needsForQuote($spa, CarbonImmutable::instance($booking->start_at), $booking->quote), $booking->id)['ok'] ?? false);
            }

            $booking = $this->transition($booking, 'declined', $actor, ['expires_at' => null, 'partner_note' => $note]);
            $decline = $booking->declines()->create([
                'spa_id' => $spa->id,
                'declined_by' => $userId,
                'actor' => $actor,
                'reason' => $reason,
                'note' => $note,
                'start_at' => $booking->start_at,
                'party' => (int) ($booking->party ?: 1),
                'engine_available' => $engineAvailable,
            ]);
            $booking->log('declined:reason', $actor, ['reason' => $reason, 'engine_available' => $engineAvailable, 'decline_id' => $decline->id]);
            if (auth()->check()) {
                ActivityLog::record('booking.declined', $booking, ['reason' => $reason, 'engine_available' => $engineAvailable, 'spa_id' => $spa->id]);
            }

            return $booking;
        });
    }

    /**
     * Annulation. Par le client : gratuite avant la limite figée sur la réservation (tarif standard) ; au-delà, ou en tarif
     * non remboursable, 100 % du montant reste dû et la commission est conservée (même mécanique que le no-show avec frais).
     * Par le partenaire (demande acceptée) ou l'administration : jamais de frais pour le client. Le créneau est toujours libéré.
     */
    public function cancel(Booking $booking, string $actor = 'client'): Booking
    {
        return $this->mutations->forBooking($booking, function (Booking $current) use ($actor): Booking {
            if (! $current->canCancel($actor)) {
                throw BookingException::make('status', 'already_inactive', [], 409);
            }
            $fee = $actor === 'client' ? $current->cancelFeeNow() : 0.0;
            $this->transition($current, 'cancelled', $actor, ['cancelled_at' => now(), 'cancelled_by' => $actor, 'expires_at' => null, 'cancel_fee' => $fee > 0 ? $fee : null]);
            $kind = $fee > 0 ? ($current->isNonRefundable() ? 'cancel:non_refundable' : 'cancel:late_fee') : 'cancel:free';
            $current->log($kind, $actor, array_filter(['fee' => $fee, 'commission' => $fee > 0 ? $current->commissionDue() : 0, 'until' => $current->freeCancellationUntil()?->toDateTimeString()]));
            $current->cancellationRequests()->where('status', 'pending')
                ->update(['status' => 'closed', 'decided_at' => now(), 'decision_note' => 'booking_cancelled:'.$actor]);

            return $current;
        });
    }

    public function complete(Booking $booking, string $actor = 'partner'): Booking
    {
        return $this->mutations->forBooking($booking, function (Booking $current) use ($actor): Booking {
            if (! $current->isConfirmed()) {
                throw BookingException::make('status', 'not_confirmed', [], 409);
            }
            if ($current->end_at->gt(now())) {
                throw BookingException::make('too_soon', 'not_finished', [], 409);
            }

            return $this->transition($current, 'completed', $actor);
        });
    }

    /**
     * Client absent. Le partenaire ne peut déclarer qu'entre l'heure du rendez-vous et NO_SHOW_WINDOW_HOURS après la fin
     * prévue ; l'administration peut corriger à tout moment après le rendez-vous, y compris une réservation déjà terminée.
     * Frais appliqués → le total reste dû et la commission HammamLuxury est conservée ; frais abandonnés → geste commercial, rien de dû.
     */
    public function noShow(Booking $booking, string $actor = 'partner', bool $applyFee = true, ?string $note = null): Booking
    {
        return $this->mutations->forBooking($booking, function (Booking $booking) use ($actor, $applyFee, $note): Booking {
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
        });
    }

    /** Réservation non honorée par l'établissement : incident sérieux, aucune commission, enregistré par l'administration seulement. */
    public function partnerNoShow(Booking $booking, string $actor = 'admin', ?string $note = null): Booking
    {
        return $this->mutations->forBooking($booking, function (Booking $booking) use ($actor, $note): Booking {
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
        });
    }

    /**
     * Déplace une réservation confirmée vers un nouveau créneau (même formule, même prix) : re-planification sous verrou
     * en excluant ses propres allocations, puis remplacement atomique des allocations.
     */
    public function reschedule(Booking $booking, CarbonImmutable $start, string $actor = 'admin'): Booking
    {
        return $this->mutations->forBooking($booking, function (Booking $booking) use ($start, $actor): Booking {
            if (! $booking->isConfirmed()) {
                throw BookingException::make('status', 'not_confirmed', [], 409);
            }
            $spa = $booking->spa;
            $this->assertFutureStart($spa, $start, enforceLead: true);
            $quote = $booking->quote ?? [];
            if (empty($quote['items'])) {
                throw BookingException::make('quote', 'quote_missing', [], 409);
            }
            $this->assertAllocationsIntact($booking);
            if ($booking->start_at->equalTo($start)) {
                return $booking;
            }
            $plan = $this->engine->plan($spa, $this->needsForQuote($spa, $start, $quote), $booking->id);
            if (! $plan['ok']) {
                throw new BookingException('unavailable', implode(' ', $plan['errors']), 409);
            }
            $old = $booking->start_at;

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

            return $booking->refresh();
        });
    }

    /** Passe en « terminée » les réservations confirmées dont l'heure de fin est dépassée (sans action du partenaire). */
    public function completePast(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $done = [];
        $cutoff = $now->subMinutes((int) config('hl.auto_complete_after_min', 240));
        $due = Booking::where('status', 'confirmed')->where('end_at', '<', $cutoff)->get();
        foreach ($due as $booking) {
            $changed = $this->mutations->forBooking($booking, function (Booking $current) use ($cutoff): bool {
                if (! $current->isConfirmed() || ! $current->end_at->lt($cutoff)) {
                    return false;
                }
                $pending = $current->pendingCancellationRequest()->lockForUpdate()->first();
                if ($pending) {
                    $this->escalateToArbitration($current, $pending);

                    return false;
                }
                $this->transition($current, 'completed', 'system');

                return true;
            });
            if ($changed) {
                $done[] = $booking->id;
            }
        }

        return $done;
    }

    /**
     * Rendez-vous passé avec une demande d'annulation partenaire ouverte : ni clôture ni commission automatiques ;
     * la réservation est signalée « à arbitrer » et HammamLuxury est alerté une seule fois.
     */
    private function escalateToArbitration(Booking $booking, CancellationRequest $request): void
    {
        if ($booking->arbitration_required_at !== null) {
            return;
        }
        $booking->update(['arbitration_required_at' => now()]);
        $request->update(['escalated_at' => now()]);
        $booking->log('arbitration:required', 'system', ['request' => $request->id]);
        $request->setRelation('booking', $booking);
        DB::afterCommit(fn () => event(new CancellationArbitrationRequired($request)));
    }

    /**
     * Décision HammamLuxury après la date du rendez-vous (arbitrage) : `cancelled` = la prestation n'a pas eu lieu,
     * aucune commission (contre-écriture si une commission avait déjà été enregistrée) ; `completed` = prestation réalisée.
     */
    public function arbitrate(Booking $booking, string $outcome): Booking
    {
        if (! in_array($outcome, ['cancelled', 'completed'], true)) {
            throw BookingException::make('outcome', 'invalid', [], 422);
        }

        return $this->mutations->forBooking($booking, function (Booking $current) use ($outcome): Booking {
            if (! $current->needsArbitration()) {
                throw BookingException::make('status', 'arbitration_not_needed', [], 409);
            }
            if ($outcome === 'cancelled') {
                $this->transition($current, 'cancelled', 'admin', ['cancelled_at' => now(), 'cancelled_by' => 'admin', 'cancel_fee' => null, 'arbitration_required_at' => null]);
                $current->log('cancel:arbitration', 'admin');
            } elseif ($current->isConfirmed()) {
                $this->transition($current, 'completed', 'admin', ['arbitration_required_at' => null]);
            } else {
                $current->update(['arbitration_required_at' => null]);
                $this->ledger->sync($current, 'admin');
            }

            return $current;
        });
    }

    public function setPaymentStatus(Booking $booking, string $actor, string $status): Booking
    {
        if (! in_array($status, Booking::PAYMENT_STATUSES, true)) {
            throw BookingException::make('payment', 'invalid_payment_status');
        }

        return $this->mutations->forBooking($booking, function (Booking $current) use ($actor, $status): Booking {
            if (! in_array($actor, ['partner', 'admin'], true)) {
                throw BookingException::make('actor', 'invalid', [], 403);
            }
            if ($current->payment_status === $status) {
                return $current;
            }
            $old = $current->payment_status;
            $current->update(['payment_status' => $status]);
            $current->log('payment:'.$status, $actor, ['from' => $old]);
            $this->ledger->sync($current, $actor);

            return $current;
        });
    }

    private function transition(Booking $booking, string $status, string $actor, array $extra = []): Booking
    {
        $old = $booking->status;
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Booking transitions require BookingMutation.');
        }
        $allowed = match ($status) {
            'confirmed', 'declined', 'expired' => ['waiting'],
            'cancelled' => $actor === 'admin' && $booking->needsArbitration() ? ['waiting', 'confirmed', 'completed'] : ['waiting', 'confirmed'],
            'completed' => ['confirmed'],
            'no_show', 'partner_no_show' => ['confirmed', 'completed'],
            default => [],
        };
        if (! in_array($old, $allowed, true)) {
            throw BookingException::make('status', 'invalid_transition', [], 409);
        }
        $booking->fill(['status' => $status] + $extra)->save();
        if (in_array($status, Booking::INACTIVE_STATUSES, true)) {
            $this->release($booking);
        }
        $this->ledger->sync($booking, $actor);
        $booking->log($status, $actor, ['from' => $old]);
        $eventBooking = clone $booking;
        DB::afterCommit(fn () => event(new BookingStatusChanged($eventBooking, $old)));

        return $booking;
    }

    /** Libère les allocations d'une réservation (idempotent). */
    private function release(Booking $booking): int
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
        $due = Booking::where('status', 'waiting')->where(function ($query) use ($now) {
            $query->where('expires_at', '<=', $now)->orWhere('start_at', '<=', $now);
        })->get();
        foreach ($due as $booking) {
            $changed = $this->mutations->forBooking($booking, function (Booking $current) use ($now): bool {
                if (! $current->isWaiting() || ((! $current->expires_at || $current->expires_at->gt($now)) && $current->start_at->gt($now))) {
                    return false;
                }
                $this->transition($current, 'expired', 'system', ['expiration_notified_at' => $now]);
                $eventBooking = clone $current;
                DB::afterCommit(fn () => event(new BookingExpired($eventBooking)));

                return true;
            });
            if ($changed) {
                $expired[] = $booking->id;
            }
        }

        return $expired;
    }

    /* ------------------------------------------------------------------ Divers */

    private function assertFutureStart(Spa $spa, CarbonImmutable $start, bool $enforceLead = false): void
    {
        $now = CarbonImmutable::now();
        if ($start <= $now) {
            throw BookingException::make('too_soon', 'start_passed', [], 409);
        }
        if ($enforceLead && $start < $now->addMinutes($spa->minLead())) {
            throw BookingException::make('too_soon', 'too_soon', ['minutes' => $spa->minLead()], 409);
        }
    }

    /** Les besoins figés évitent qu'un changement de catalogue raccourcisse une prestation vendue. */
    private function needsForQuote(Spa $spa, CarbonImmutable $start, array $quote): array
    {
        if (! empty($quote['capacity_needs'])) {
            return array_map(function (array $need) use ($start): array {
                $need['start'] = $start->addMinutes((int) $need['start_offset_min']);
                $need['end'] = $start->addMinutes((int) $need['end_offset_min']);

                return $need;
            }, $quote['capacity_needs']);
        }

        // Anciennes réservations : refus explicite si leur détail n'est plus reconstructible.
        if (empty($quote['items'])) {
            throw BookingException::make('quote', 'quote_missing', [], 409);
        }
        try {
            $needs = $this->engine->expandNeeds($spa, $start, $quote['items']);
        } catch (BookingException) {
            throw BookingException::make('quote', 'quote_missing', [], 409);
        }
        $end = $needs ? max(array_column($needs, 'end')) : $start;
        if ((int) $start->diffInMinutes($end) !== (int) $quote['duration_min']) {
            throw BookingException::make('quote', 'quote_missing', [], 409);
        }

        return $needs;
    }

    /** Une réservation en attente doit conserver l'ensemble de ses allocations, pas seulement une ligne. */
    private function assertAllocationsIntact(Booking $booking): void
    {
        $expected = [];
        $actual = [];
        $needs = $this->needsForQuote($booking->spa, CarbonImmutable::instance($booking->start_at), $booking->quote ?? []);
        $key = fn ($type, $participant, $treatment, $start, $end) => implode('|', [(int) $type, (int) $participant, (int) $treatment, $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')]);
        foreach ($needs as $need) {
            $k = $key($need['type_id'], $need['participant_no'], $need['treatment_id'], $need['start'], $need['end']);
            $expected[$k] = ($expected[$k] ?? 0) + (int) $need['party'];
        }
        $participants = $booking->participants()->pluck('participant_no', 'id');
        $resources = $booking->spa->resources->keyBy('id');
        foreach ($booking->allocations()->where('status', 'active')->get() as $allocation) {
            $resource = $resources->get($allocation->resource_id);
            if (! $resource || $resource->status !== 'active' || (int) $resource->resource_type_id !== (int) $allocation->resource_type_id) {
                throw BookingException::make('allocations_missing', 'allocations_missing', [], 409);
            }
            $k = $key($allocation->resource_type_id, $participants->get($allocation->booking_participant_id, 0), $allocation->treatment_id, $allocation->start_at, $allocation->end_at);
            $actual[$k] = ($actual[$k] ?? 0) + (int) $allocation->party;
        }
        ksort($expected);
        ksort($actual);
        if (! $expected || $actual !== $expected) {
            throw BookingException::make('allocations_missing', 'allocations_missing', [], 409);
        }
    }

    private function sameCustomer(Booking $booking, array $customer): bool
    {
        $text = fn ($value) => mb_strtolower(trim((string) $value));
        $phone = PhoneNumber::normalize($customer['phone'] ?? null) ?? ($customer['phone'] ?? '');

        return $text($booking->first_name) === $text($customer['first_name'] ?? '')
            && $text($booking->last_name) === $text($customer['last_name'] ?? '')
            && $text($booking->email) === $text($customer['email'] ?? '')
            && $booking->phone === $phone
            && (int) ($booking->user_id ?? 0) === (int) ($customer['user_id'] ?? 0);
    }

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
