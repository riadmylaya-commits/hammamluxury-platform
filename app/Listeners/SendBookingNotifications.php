<?php

namespace App\Listeners;

use App\Events\BookingCreated;
use App\Events\BookingExpired;
use App\Events\BookingStatusChanged;
use App\Mail\BookingMail;
use App\Models\Booking;
use Illuminate\Support\Facades\Mail;

/**
 * E-mails client + partenaire à chaque étape. Idempotent : la trace `notified:<event>` dans
 * booking_events empêche tout second envoi pour le même événement.
 */
class SendBookingNotifications
{
    public function handleCreated(BookingCreated $e): void
    {
        $this->send($e->booking, 'created');
    }

    public function handleStatus(BookingStatusChanged $e): void
    {
        if (in_array($e->booking->status, ['confirmed', 'declined', 'cancelled'], true)) {
            $this->send($e->booking, $e->booking->status);
        }
    }

    public function handleExpired(BookingExpired $e): void
    {
        $this->send($e->booking, 'expired');
    }

    private function send(Booking $booking, string $event): void
    {
        if ($booking->events()->where('type', 'notified:'.$event)->exists()) {
            return;
        }
        $booking->log('notified:'.$event, 'system');
        $partnerEmail = $booking->spa->partner?->user?->email;

        if ($booking->email) {
            Mail::to($booking->email)->send((new BookingMail($booking, $event, 'client'))->locale($booking->locale ?: 'fr'));
        }
        if ($partnerEmail) {
            Mail::to($partnerEmail)->send((new BookingMail($booking, $event, 'partner'))->locale($booking->spa->partner->user->locale ?: 'fr'));
        }
    }
}
