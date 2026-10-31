<?php

namespace App\Jobs;

use App\Mail\BookingMail;
use App\Mail\ReviewInviteMail;
use App\Models\BookingNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Envoi d'une notification de réservation à un destinataire ; l'état est écrit après l'envoi réel, jamais avant.
 * L'événement « review_invite » (client) porte l'invitation à laisser un avis : même trace, même reprise.
 */
class SendBookingNotification implements ShouldQueue
{
    use Queueable;

    public const REVIEW_INVITE = 'review_invite';

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public int $notificationId) {}

    public function handle(): void
    {
        $n = BookingNotification::with('booking.spa.partner.user', 'booking.review')->find($this->notificationId);
        if (! $n || $n->status === 'sent') {
            return;
        }
        $booking = $n->booking;
        $locale = $n->audience === 'partner' ? ($booking->spa->decisionUser()?->locale ?: 'fr') : ($booking->locale ?: 'fr');

        try {
            Mail::to($n->recipient)->sendNow($this->mailable($n)->locale($locale));
        } catch (Throwable $e) {
            $n->update(['status' => 'failed', 'attempts' => $n->attempts + 1, 'failed_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 500)]);
            if (! $booking->events()->where('type', 'notification_failed:'.$n->event)->exists()) {
                $booking->log('notification_failed:'.$n->event, 'system');
            }
            throw $e;
        }

        $n->update(['status' => 'sent', 'attempts' => $n->attempts + 1, 'sent_at' => now(), 'error' => null]);
        // L'historique n'indique « e-mail envoyé » qu'après remise effective au serveur d'envoi.
        if (! $booking->events()->where('type', 'notified:'.$n->event)->exists()) {
            $booking->log('notified:'.$n->event, 'system');
        }
    }

    private function mailable(BookingNotification $n): BookingMail|ReviewInviteMail
    {
        if ($n->event === self::REVIEW_INVITE) {
            $review = $n->booking->review ?? throw new \RuntimeException('Invitation à l’avis sans avis associé (réservation '.$n->booking->reference.')');

            return new ReviewInviteMail($review);
        }

        return new BookingMail($n->booking, $n->event, $n->audience);
    }

    public function failed(Throwable $e): void
    {
        BookingNotification::whereKey($this->notificationId)->where('status', '!=', 'sent')
            ->update(['status' => 'failed', 'failed_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 500)]);
    }
}
