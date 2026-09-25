<?php

namespace App\Mail;

use App\Models\Spa;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Cycle de validation d'un établissement : submitted (→ admin), published | refused (→ partenaire). */
class SpaStatusMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public Spa $spa, public string $event, public string $audience) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('emails.spa.'.$this->event.'.subject', ['spa' => $this->spa->name]));
    }

    public function content(): Content
    {
        $url = $this->audience === 'admin'
            ? url('/admin/spas/'.$this->spa->id.'/edit')
            : url('/partenaire/'.$this->spa->slug);

        return new Content(markdown: 'emails.spa-status', with: ['spa' => $this->spa, 'event' => $this->event, 'url' => $url]);
    }
}
