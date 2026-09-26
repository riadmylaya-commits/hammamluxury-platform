<?php

namespace App\Domain\Booking;

use App\Domain\Phone\PhoneNumber;
use App\Domain\Privacy\ContactMasker;
use App\Events\BookingCreated;
use App\Events\BookingExpired;
use App\Events\BookingStatusChanged;
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
        $quote = $this->quotes->build($spa, $participants);
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
    public function book(Spa $spa, CarbonImmutable $start, array $items, array $customer, string $status = 'waiting'): Booking
    {
        $participants = [];
        foreach (array_values($items) as $i => $item) {
            $participants[] = ['participant_no' => $item['participant_no'] ?? $i + 1, 'treatment' => $item['treatment'], 'party' => $item['party'] ?? 1, 'extras' => $item['extras'] ?? []];
        }
        $quote = $this->quotes->fromRequest($spa, ['participants' => $participants]);
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

        return $this->transition($booking, 'confirmed', $actor, ['confirmed_at' => now(), 'expires_at' => null, 'partner_note' => $note]);
    }

    public function decline(Booking $booking, string $actor = 'partner', ?string $note = null): Booking
    {
        if (! $booking->isWaiting()) {
            throw BookingException::make('status', 'not_waiting', [], 409);
        }

        return $this->transition($booking, 'declined', $actor, ['expires_at' => null, 'partner_note' => $note]);
    }

    public function cancel(Booking $booking, string $actor = 'client'): Booking
    {
        if (! $booking->isActive()) {
            throw BookingException::make('status', 'already_inactive', [], 409);
        }

        return $this->transition($booking, 'cancelled', $actor, ['cancelled_at' => now(), 'cancelled_by' => $actor, 'expires_at' => null]);
    }

    public function complete(Booking $booking, string $actor = 'partner'): Booking
    {
        if (! $booking->isConfirmed()) {
            throw BookingException::make('status', 'not_confirmed', [], 409);
        }

        return $this->transition($booking, 'completed', $actor);
    }

    public function noShow(Booking $booking, string $actor = 'partner'): Booking
    {
        if (! $booking->isConfirmed()) {
            throw BookingException::make('status', 'not_confirmed', [], 409);
        }

        return $this->transition($booking, 'no_show', $actor);
    }

    /** Passe en « terminée » les réservations confirmées dont l'heure de fin est dépassée (sans action du partenaire). */
    public function completePast(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $done = [];
        $due = Booking::where('status', 'confirmed')->where('end_at', '<=', $now->subMinutes((int) config('hl.auto_complete_after_min', 60)))->get();
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
            if ($status === 'completed') {
                LedgerEntry::firstOrCreate(
                    ['booking_id' => $booking->id, 'type' => 'commission'],
                    ['partner_id' => $booking->spa->partner_id, 'amount' => $booking->commission_amount, 'currency' => $booking->currency],
                );
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
