<?php

namespace App\Mail;

use App\Models\BookingMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Nouveau message dans la conversation d'une réservation, avec lien sécurisé vers le fil (client ou partenaire). */
class BookingMessageMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public BookingMessage $message, public string $audience) {}

    public function envelope(): Envelope
    {
        $b = $this->message->booking;

        return new Envelope(subject: __("emails.message.{$this->audience}.subject", ['ref' => $b->reference, 'spa' => $b->spa->name]));
    }

    public function content(): Content
    {
        $b = $this->message->booking;
        $url = $this->audience === 'partner'
            ? url('/partenaire/'.$b->spa->slug.'/bookings/'.$b->id)
            : route('booking.show', ['locale' => $b->locale ?: 'fr', 'token' => $b->manage_token]).'#messages';

        return new Content(markdown: 'emails.message', with: ['message' => $this->message, 'booking' => $b, 'audience' => $this->audience, 'url' => $url]);
    }
}
