<?php

namespace App\Listeners;

use App\Events\BookingCreated;
use App\Events\BookingExpired;
use App\Events\BookingStatusChanged;
use App\Jobs\SendBookingNotification;
use App\Models\Booking;
use App\Models\BookingNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * E-mails client + partenaire à chaque étape. Une ligne booking_notifications par destinataire (unique par réservation/événement/destinataire) :
 * « prévue » à la création, « en file » après mise en file, « envoyée »/« échouée » écrites par le job. Aucun doublon en cas de rejeu.
 */
class SendBookingNotifications
{
    public function handleCreated(BookingCreated $e): void
    {
        if ($e->booking->source === 'partner') {
            return; // saisie par l'établissement lui-même : aucun e-mail automatique
        }
        $this->plan($e->booking, $e->booking->status === 'confirmed' ? 'confirmed' : 'created');
    }

    public function handleStatus(BookingStatusChanged $e): void
    {
        if (in_array($e->booking->status, ['confirmed', 'declined', 'cancelled'], true)) {
            $this->plan($e->booking, $e->booking->status);
        }
    }

    public function handleExpired(BookingExpired $e): void
    {
        $this->plan($e->booking, 'expired');
    }

    private function plan(Booking $booking, string $event): void
    {
        $recipients = array_filter([
            'client' => $booking->email,
            'partner' => $booking->spa->partner?->user?->email,
        ]);

        foreach ($recipients as $audience => $recipient) {
            self::dispatch($booking, $event, $audience, $recipient);
        }
    }

    /** Crée la trace si elle n'existe pas encore et met le job en file ; idempotent par (réservation, événement, destinataire). */
    public static function dispatch(Booking $booking, string $event, string $audience, string $recipient): ?BookingNotification
    {
        try {
            $n = BookingNotification::create(['booking_id' => $booking->id, 'event' => $event, 'audience' => $audience, 'recipient' => $recipient]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        DB::afterCommit(function () use ($n) {
            $n->update(['status' => 'queued', 'queued_at' => now()]);
            SendBookingNotification::dispatch($n->id);
        });

        return $n;
    }

    /** Remet en file les notifications échouées ou restées « prévues » (perte de file) ; retourne le nombre relancé. */
    public static function retry(int $maxAttempts = 5): int
    {
        $stuck = BookingNotification::where(fn ($q) => $q->where('status', 'failed')
            ->orWhere(fn ($q) => $q->where('status', 'planned')->where('created_at', '<', now()->subMinutes(10)))
            ->orWhere(fn ($q) => $q->where('status', 'queued')->where('queued_at', '<', now()->subHours(1))))
            ->where('attempts', '<', $maxAttempts)->get();

        foreach ($stuck as $n) {
            $n->update(['status' => 'queued', 'queued_at' => now()]);
            SendBookingNotification::dispatch($n->id);
        }

        return $stuck->count();
    }
}
