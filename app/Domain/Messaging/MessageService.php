<?php

namespace App\Domain\Messaging;

use App\Domain\Booking\BookingException;
use App\Domain\Privacy\ContactMasker;
use App\Mail\BookingMessageMail;
use App\Models\Booking;
use App\Models\BookingMessage;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * Messagerie interne client ↔ établissement rattachée à une réservation.
 * Le client n'a pas de compte : il écrit depuis son lien de suivi sécurisé.
 */
class MessageService
{
    public const MAX_LENGTH = 2000;

    public function send(Booking $booking, string $sender, string $body, ?User $user = null): BookingMessage
    {
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > self::MAX_LENGTH) {
            throw BookingException::make('body', 'message_invalid', ['max' => self::MAX_LENGTH], 422);
        }
        if (! in_array($sender, BookingMessage::SENDERS, true)) {
            throw BookingException::make('sender', 'invalid', [], 422);
        }
        if (! $booking->isActive() && $booking->status !== 'completed') {
            throw BookingException::make('status', 'message_closed', [], 409);
        }

        // Tant que la réservation n'est pas confirmée, les coordonnées directes sont masquées (même règle que la remarque client).
        if (! $booking->isConfirmed() && $booking->status !== 'completed') {
            $body = ContactMasker::redact($body);
        }

        $message = $booking->messages()->create([
            'spa_id' => $booking->spa_id,
            'sender' => $sender,
            'user_id' => $user?->id,
            'body' => $body,
        ]);

        $this->notify($message);

        return $message;
    }

    /** Marque comme lus les messages de l'autre partie ; retourne le nombre de messages marqués. */
    public function markRead(Booking $booking, string $reader): int
    {
        return $booking->messages()->unreadFor($reader)->update(['read_at' => now()]);
    }

    /**
     * Notifie le destinataire par e-mail avec lien vers la conversation. Un seul e-mail par "salve" :
     * on n'envoie rien si un message précédent du même expéditeur est encore non lu et déjà notifié.
     */
    private function notify(BookingMessage $message): void
    {
        $booking = $message->booking;
        $alreadyAlerted = $booking->messages()
            ->where('sender', $message->sender)->whereKeyNot($message->id)
            ->whereNull('read_at')->whereNotNull('notified_at')->exists();
        if ($alreadyAlerted) {
            return;
        }

        if ($message->sender === 'client') {
            $user = $booking->spa->partner?->user;
            if ($user?->email) {
                Mail::to($user->email)->queue((new BookingMessageMail($message, 'partner'))->locale($user->locale ?: 'fr'));
                $message->update(['notified_at' => now()]);
            }
        } elseif ($booking->email) {
            Mail::to($booking->email)->queue((new BookingMessageMail($message, 'client'))->locale($booking->locale ?: 'fr'));
            $message->update(['notified_at' => now()]);
        }
    }
}
