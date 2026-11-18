<?php

namespace App\Mail;

use App\Models\SupportRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** E-mails d'assistance : accusé de réception (ack), alerte à l'administration (admin), réponse au demandeur (reply). */
class SupportMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public SupportRequest $request, public string $kind, public ?string $body = null)
    {
        $this->locale($kind === 'admin' ? 'fr' : $request->locale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('support.mail.'.$this->kind.'.subject', ['ref' => $this->request->reference, 'subject' => $this->request->subject]),
            replyTo: [$this->kind === 'admin' ? new Address($this->request->email, $this->request->name) : new Address(config('hl.contact_email'))],
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.support', with: ['request' => $this->request, 'kind' => $this->kind, 'body' => $this->body]);
    }
}
