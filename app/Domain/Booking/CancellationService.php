<?php

namespace App\Domain\Booking;

use App\Mail\CancellationRequestMail;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\CancellationRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Circuit de demande d'annulation partenaire : la réservation reste confirmée (aucune libération de capacité)
 * jusqu'à la décision finale de HammamLuxury ; le client est informé et peut donner son accord.
 */
class CancellationService
{
    public function __construct(private BookingService $bookings, private BookingMutation $mutations) {}

    /**
     * Étape 1 — le partenaire dépose une demande motivée : motif structuré + explication (+ justificatif).
     * Après un refus, une nouvelle demande n'est possible que pour un autre motif (nouvel événement).
     */
    public function request(Booking $booking, ?User $requester, string $reason, ?string $reasonCode = null, ?string $evidencePath = null): CancellationRequest
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 20) {
            throw BookingException::make('reason', 'cancellation_reason_required', [], 422);
        }
        if ($reasonCode !== null && ! in_array($reasonCode, CancellationRequest::REASON_CODES, true)) {
            throw BookingException::make('reason_code', 'cancellation_reason_code_required', [], 422);
        }

        return $this->mutations->forBooking($booking, function (Booking $booking) use ($requester, $reason, $reasonCode, $evidencePath) {
            $pending = $booking->pendingCancellationRequest()->lockForUpdate()->first();
            if ($pending && $booking->isConfirmed() && $booking->start_at->isFuture()
                && $pending->requested_by === $requester?->id && $pending->reason === $reason
                && $pending->reason_code === $reasonCode && $pending->evidence_path === $evidencePath) {
                return $pending; // Rejeu exact : une demande et une seule série de notifications.
            }
            if (! $booking->canRequestCancellation()) {
                throw BookingException::make('status', 'cancellation_not_allowed', [], 409);
            }
            if ($reasonCode !== null && $booking->cancellationRequests()->where('status', 'refused')->where('reason_code', $reasonCode)->exists()) {
                throw BookingException::make('reason_code', 'cancellation_same_reason_refused', [], 409);
            }
            $request = $booking->cancellationRequests()->create([
                'spa_id' => $booking->spa_id,
                'requested_by' => $requester?->id,
                'reason' => $reason,
                'reason_code' => $reasonCode,
                'evidence_path' => $evidencePath,
                'status' => 'pending',
            ]);
            $booking->log('cancellation:requested', 'partner', array_filter(['request' => $request->id, 'reason_code' => $reasonCode, 'evidence' => (bool) $evidencePath]));
            ActivityLog::record('booking.cancellation_requested', $booking, array_filter(['ref' => $booking->reference, 'reason_code' => $reasonCode, 'reason' => $reason]), 'partner');
            $request->setRelation('booking', $booking);
            DB::afterCommit(function () use ($request) {
                $this->notifyClient($request);
                $this->notifyAdmins($request, 'requested');
            });

            return $request;
        });
    }

    /** Étape 2 — le client répond depuis son lien de suivi sécurisé (accord ou refus). */
    public function clientRespond(CancellationRequest $request, string $response): CancellationRequest
    {
        if (! in_array($response, ['accepted', 'refused'], true)) {
            throw BookingException::make('response', 'invalid', [], 422);
        }

        return $this->mutateRequest($request, function (CancellationRequest $request, Booking $booking) use ($response) {
            if ($request->isPending() && $request->client_response === $response) {
                return $request;
            }
            if (! $request->isPending() || $request->client_response !== null || ! $booking->canCancel('client')) {
                throw BookingException::make('status', 'cancellation_already_answered', [], 409);
            }
            $request->update(['client_response' => $response, 'client_responded_at' => now()]);
            $booking->log('cancellation:client_'.$response, 'client', ['request' => $request->id]);
            DB::afterCommit(function () use ($request) {
                $this->notifyAdmins($request, 'client_response');
                $this->notifyPartner($request, 'client_response');
            });

            return $request;
        });
    }

    /**
     * Étape 2 bis — HammamLuxury propose une nouvelle date (ou une solution alternative décrite en note).
     * La réservation ne change pas tant que le client n'a pas accepté.
     */
    public function propose(CancellationRequest $request, User $admin, CarbonImmutable $start, ?string $note = null): CancellationRequest
    {
        $this->assertAdmin($admin);

        return $this->mutateRequest($request, function (CancellationRequest $request, Booking $booking) use ($start, $note) {
            if (! $request->isPending() || ! $booking->canCancel('admin')) {
                throw BookingException::make('status', 'cancellation_already_decided', [], 409);
            }
            if ($start->lte(now())) {
                throw BookingException::make('proposed_start_at', 'proposal_in_past', [], 422);
            }
            if ($request->hasOpenProposal()) {
                if ($request->proposed_start_at->equalTo($start) && $request->proposal_note === ($note ? trim($note) : null)) {
                    return $request;
                }
                throw BookingException::make('status', 'cancellation_proposal_pending', [], 409);
            }
            $request->update([
                'proposed_start_at' => $start,
                'proposal_note' => $note ? trim($note) : null,
                'proposed_at' => now(),
                'proposal_response' => null,
                'proposal_responded_at' => null,
            ]);
            $booking->log('cancellation:proposed', 'admin', ['request' => $request->id, 'start_at' => $start->toDateTimeString()]);
            ActivityLog::record('booking.reschedule_proposed', $booking, array_filter(['ref' => $booking->reference, 'start_at' => $start->toDateTimeString(), 'note' => $note]), 'admin');
            if ($booking->email) {
                DB::afterCommit(fn () => $this->mail($booking->email, $request, 'proposed', 'client', $booking->locale));
            }

            return $request;
        });
    }

    /**
     * Étape 2 ter — le client répond à la proposition : acceptée → la réservation est déplacée (sous verrou) et la demande close ;
     * refusée → la demande revient à HammamLuxury pour décision finale.
     */
    public function clientRespondProposal(CancellationRequest $request, string $response): CancellationRequest
    {
        if (! in_array($response, ['accepted', 'refused'], true)) {
            throw BookingException::make('response', 'invalid', [], 422);
        }

        return $this->mutateRequest($request, function (CancellationRequest $request, Booking $booking) use ($response) {
            if ($request->proposal_response === $response
                && ($request->status === 'rescheduled' || ($request->isPending() && $response === 'refused'))) {
                return $request;
            }
            if (! $request->hasOpenProposal() || ! $booking->canCancel('client')) {
                throw BookingException::make('status', 'cancellation_no_open_proposal', [], 409);
            }
            if ($response === 'accepted') {
                $booking = $this->bookings->reschedule($booking, CarbonImmutable::instance($request->proposed_start_at), 'client');
                $request->setRelation('booking', $booking);
                $request->update(['proposal_response' => 'accepted', 'proposal_responded_at' => now(), 'status' => 'rescheduled', 'decided_at' => now()]);
                $booking->log('cancellation:rescheduled', 'client', ['request' => $request->id]);
                DB::afterCommit(function () use ($request, $booking) {
                    $this->notifyAdmins($request, 'rescheduled');
                    $this->notifyPartner($request, 'rescheduled');
                    if ($booking->email) {
                        $this->mail($booking->email, $request, 'rescheduled', 'client', $booking->locale);
                    }
                });
            } else {
                $request->update(['proposal_response' => 'refused', 'proposal_responded_at' => now()]);
                $booking->log('cancellation:proposal_refused', 'client', ['request' => $request->id]);
                DB::afterCommit(fn () => $this->notifyAdmins($request, 'proposal_refused'));
            }

            return $request;
        });
    }

    /** Étape 3 — décision finale HammamLuxury : seule l'acceptation annule réellement et libère la capacité. */
    public function decide(CancellationRequest $request, User $admin, string $decision, ?string $note = null): CancellationRequest
    {
        if (! in_array($decision, ['accepted', 'refused'], true)) {
            throw BookingException::make('decision', 'invalid', [], 422);
        }
        $this->assertAdmin($admin);

        return $this->mutateRequest($request, function (CancellationRequest $request, Booking $booking) use ($admin, $decision, $note) {
            if ($request->status === $decision && $request->decided_by === $admin->id
                && $request->decision_note === ($note ? trim($note) : null)) {
                return $request;
            }
            $arbitration = $booking->needsArbitration();
            if (! $request->isPending() || (! $arbitration && ! $booking->canCancel('admin'))) {
                throw BookingException::make('status', 'cancellation_already_decided', [], 409);
            }
            if ($request->hasOpenProposal() && ! $arbitration) {
                throw BookingException::make('status', 'cancellation_proposal_pending', [], 409);
            }
            if ($arbitration) {
                $this->bookings->arbitrate($booking, $decision === 'accepted' ? 'cancelled' : 'completed');
            } elseif ($decision === 'accepted') {
                $this->bookings->cancel($booking, 'admin');
            }
            $request->refresh()->setRelation('booking', $booking);
            $request->update([
                'status' => $decision,
                'decided_by' => $admin->id,
                'decided_at' => now(),
                'decision_note' => $note ? trim($note) : null,
            ]);
            $booking->log('cancellation:'.$decision, 'admin', ['request' => $request->id]);
            ActivityLog::record('booking.cancellation_'.$decision, $booking, array_filter(['ref' => $booking->reference, 'note' => $note]), 'admin');
            if ($decision === 'refused' && ! $arbitration) {
                DB::afterCommit(function () use ($request, $booking) {
                    $this->notifyPartner($request, 'refused');
                    if ($booking->email) {
                        $this->mail($booking->email, $request, 'refused', 'client', $booking->locale);
                    }
                });
            }

            return $request;
        });
    }

    /** Toujours verrouiller dans l'ordre établissement → réservation → demande. */
    private function mutateRequest(CancellationRequest $request, callable $callback): CancellationRequest
    {
        $result = $this->mutations->forBooking($request->booking()->firstOrFail(), function (Booking $booking) use ($request, $callback) {
            $fresh = CancellationRequest::whereKey($request->id)->where('booking_id', $booking->id)->lockForUpdate()->firstOrFail();
            $fresh->setRelation('booking', $booking);

            return $callback($fresh, $booking);
        });
        $request->setRawAttributes($result->getAttributes(), true);
        $request->setRelations($result->getRelations());

        return $result;
    }

    private function assertAdmin(User $admin): void
    {
        if ($admin->role !== 'admin') {
            throw BookingException::make('actor', 'admin_only', [], 403);
        }
    }

    /** Nombre et taux de demandes d'annulation par établissement (sur les réservations confirmées ou terminées). */
    public static function rateFor(int $spaId): array
    {
        $requests = CancellationRequest::where('spa_id', $spaId)->count();
        $bookings = Booking::where('spa_id', $spaId)->whereIn('status', ['confirmed', 'completed', 'cancelled', 'no_show', 'partner_no_show'])->count();

        return ['requests' => $requests, 'bookings' => $bookings, 'rate' => $bookings ? round($requests * 100 / $bookings, 1) : 0.0];
    }

    private function notifyClient(CancellationRequest $request): void
    {
        $booking = $request->booking;
        if ($booking->email) {
            $this->mail($booking->email, $request, 'requested', 'client', $booking->locale);
        }
        $request->update(['client_notified_at' => now()]);
        $booking->log('cancellation:client_notified', 'system', ['request' => $request->id]);
    }

    /** Alerte HammamLuxury quand un rendez-vous est passé avec une demande encore ouverte (une seule fois par demande). */
    public function notifyArbitration(CancellationRequest $request): void
    {
        $this->notifyAdmins($request, 'arbitration');
    }

    /**
     * Rappel J-1 aux administrateurs : demandes encore sans décision dont le rendez-vous a lieu dans moins de 24 h.
     *
     * @return array<int> identifiants des demandes rappelées
     */
    public function remindAdmins(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $sent = [];
        $due = CancellationRequest::where('status', 'pending')->whereNull('reminder_sent_at')
            ->whereHas('booking', fn ($q) => $q->where('status', 'confirmed')->whereBetween('start_at', [$now, $now->addDay()]))
            ->with('booking')->get();
        foreach ($due as $request) {
            $updated = CancellationRequest::whereKey($request->id)->whereNull('reminder_sent_at')->update(['reminder_sent_at' => $now]);
            if ($updated) {
                $request->booking->log('cancellation:reminder', 'system', ['request' => $request->id]);
                $this->notifyAdmins($request, 'reminder');
                $sent[] = $request->id;
            }
        }

        return $sent;
    }

    private function notifyAdmins(CancellationRequest $request, string $event): void
    {
        foreach (User::where('role', 'admin')->get() as $admin) {
            $this->mail($admin->email, $request, $event, 'admin', $admin->locale);
        }
    }

    private function notifyPartner(CancellationRequest $request, string $event): void
    {
        $user = $request->booking->spa->partner?->user;
        if ($user?->email) {
            $this->mail($user->email, $request, $event, 'partner', $user->locale);
        }
    }

    private function mail(string $to, CancellationRequest $request, string $event, string $audience, ?string $locale): void
    {
        Mail::to($to)->queue((new CancellationRequestMail($request, $event, $audience))->locale($locale ?: 'fr'));
    }
}
