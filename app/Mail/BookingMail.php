<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Un seul mailable paramétré par événement (created, confirmed, declined, cancelled, expired) et destinataire (client | partner). */
class BookingMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public Booking $booking, public string $event, public string $audience) {}

    public function envelope(): Envelope
    {
        $subject = __('emails.'.$this->event.'.'.$this->textAudience().'.subject', ['ref' => $this->booking->reference, 'spa' => $this->booking->spa->name]);
        if ($this->event === 'created' && $this->audience === 'partner' && $this->booking->last_minute) {
            $subject = __('emails.created.last_minute_subject').$subject;
        }

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.booking', with: ['booking' => $this->booking, 'event' => $this->event, 'audience' => $this->audience, 'textAudience' => $this->textAudience()]);
    }

    /** Clé de texte : « admin » pour le destinataire côté établissement d'une fiche gérée par HammamLuxury, « client_referenced » pour le client d'une fiche référencée. */
    public function textAudience(): string
    {
        if ($this->audience === 'partner' && $this->booking->spa->managedByAdmin()) {
            return 'admin';
        }
        if ($this->audience === 'client' && $this->booking->spa->isReferenced() && in_array($this->event, ['created', 'declined', 'expired'], true)) {
            return 'client_referenced';
        }

        return $this->audience;
    }
}
