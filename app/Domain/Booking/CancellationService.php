<?php

namespace App\Domain\Booking;

use App\Mail\CancellationRequestMail;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\CancellationRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Circuit de demande d'annulation partenaire : la réservation reste confirmée (aucune libération de capacité)
 * jusqu'à la décision finale de HammamLuxury ; le client est informé et peut donner son accord.
 */
class CancellationService
{
    public function __construct(private BookingService $bookings) {}

    /** Étape 1 — le partenaire dépose une demande motivée. */
    public function request(Booking $booking, ?User $requester, string $reason): CancellationRequest
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            throw BookingException::make('reason', 'cancellation_reason_required', [], 422);
        }
        if (! $booking->canRequestCancellation()) {
            throw BookingException::make('status', 'cancellation_not_allowed', [], 409);
        }

        $request = DB::transaction(function () use ($booking, $requester, $reason) {
            $request = $booking->cancellationRequests()->create([
                'spa_id' => $booking->spa_id,
                'requested_by' => $requester?->id,
                'reason' => $reason,
                'status' => 'pending',
            ]);
            $booking->log('cancellation:requested', 'partner', ['request' => $request->id]);
            ActivityLog::record('booking.cancellation_requested', $booking, ['ref' => $booking->reference, 'reason' => $reason], 'partner');

            return $request;
        });

        $this->notifyClient($request);
        $this->notifyAdmins($request, 'requested');

        return $request;
    }

    /** Étape 2 — le client répond depuis son lien de suivi sécurisé (accord ou refus). */
    public function clientRespond(CancellationRequest $request, string $response): CancellationRequest
    {
        if (! in_array($response, ['accepted', 'refused'], true)) {
            throw BookingException::make('response', 'invalid', [], 422);
        }
        if (! $request->isPending() || $request->client_response !== null) {
            throw BookingException::make('status', 'cancellation_already_answered', [], 409);
        }

        $request->update(['client_response' => $response, 'client_responded_at' => now()]);
        $request->booking->log('cancellation:client_'.$response, 'client', ['request' => $request->id]);

        $this->notifyAdmins($request, 'client_response');
        $this->notifyPartner($request, 'client_response');

        return $request;
    }

    /** Étape 3 — décision finale HammamLuxury : seule l'acceptation annule réellement et libère la capacité. */
    public function decide(CancellationRequest $request, User $admin, string $decision, ?string $note = null): CancellationRequest
    {
        if (! in_array($decision, ['accepted', 'refused'], true)) {
            throw BookingException::make('decision', 'invalid', [], 422);
        }
        if (! $request->isPending()) {
            throw BookingException::make('status', 'cancellation_already_decided', [], 409);
        }

        DB::transaction(function () use ($request, $admin, $decision, $note) {
            $request->update([
                'status' => $decision,
                'decided_by' => $admin->id,
                'decided_at' => now(),
                'decision_note' => $note ? trim($note) : null,
            ]);
            $booking = $request->booking;
            $booking->log('cancellation:'.$decision, 'admin', ['request' => $request->id]);
            ActivityLog::record('booking.cancellation_'.$decision, $booking, array_filter(['ref' => $booking->reference, 'note' => $note]), 'admin');

            if ($decision === 'accepted' && $booking->isActive()) {
                $this->bookings->cancel($booking, 'admin');
            }
        });

        if ($decision === 'refused') {
            $this->notifyPartner($request, 'refused');
            if ($request->booking->email) {
                $this->mail($request->booking->email, $request, 'refused', 'client', $request->booking->locale);
            }
        }

        return $request->refresh();
    }

    /** Nombre et taux de demandes d'annulation par établissement (sur les réservations confirmées ou terminées). */
    public static function rateFor(int $spaId): array
    {
        $requests = CancellationRequest::where('spa_id', $spaId)->count();
        $bookings = Booking::where('spa_id', $spaId)->whereIn('status', ['confirmed', 'completed', 'cancelled', 'no_show'])->count();

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
