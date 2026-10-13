<?php

namespace App\Jobs;

use App\Mail\BookingMail;
use App\Models\BookingNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Envoi d'une notification de réservation à un destinataire ; l'état est écrit après l'envoi réel, jamais avant. */
class SendBookingNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public int $notificationId) {}

    public function handle(): void
    {
        $n = BookingNotification::with('booking.spa.partner.user')->find($this->notificationId);
        if (! $n || $n->status === 'sent') {
            return;
        }
        $booking = $n->booking;
        $locale = $n->audience === 'partner' ? ($booking->spa->partner?->user?->locale ?: 'fr') : ($booking->locale ?: 'fr');

        try {
            Mail::to($n->recipient)->sendNow((new BookingMail($booking, $n->event, $n->audience))->locale($locale));
        } catch (Throwable $e) {
            $n->update(['status' => 'failed', 'attempts' => $n->attempts + 1, 'failed_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 500)]);
            throw $e;
        }

        $n->update(['status' => 'sent', 'attempts' => $n->attempts + 1, 'sent_at' => now(), 'error' => null]);
    }

    public function failed(Throwable $e): void
    {
        BookingNotification::whereKey($this->notificationId)->where('status', '!=', 'sent')
            ->update(['status' => 'failed', 'failed_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 500)]);
    }
}
