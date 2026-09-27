<?php

namespace App\Mail;

use App\Models\CancellationRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** E-mails du circuit de demande d'annulation : `requested` | `client_response` | `refused`, audience client | partner | admin. */
class CancellationRequestMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public CancellationRequest $request, public string $event, public string $audience) {}

    public function envelope(): Envelope
    {
        $b = $this->request->booking;

        return new Envelope(subject: __("emails.cancellation.{$this->event}.{$this->audience}.subject", ['ref' => $b->reference, 'spa' => $b->spa->name]));
    }

    public function content(): Content
    {
        $b = $this->request->booking;
        $url = match ($this->audience) {
            'admin' => url('/admin/cancellation-requests/'.$this->request->id),
            'partner' => url('/partenaire/'.$b->spa->slug.'/bookings/'.$b->id),
            default => route('booking.show', ['locale' => $b->locale ?: 'fr', 'token' => $b->manage_token]),
        };

        return new Content(markdown: 'emails.cancellation', with: ['request' => $this->request, 'booking' => $b, 'event' => $this->event, 'audience' => $this->audience, 'url' => $url]);
    }
}
