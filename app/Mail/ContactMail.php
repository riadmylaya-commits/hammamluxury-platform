<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Ancien e-mail du formulaire Contact (remplacé par SupportMail) : conservé pour que les envois déjà en file d'attente au moment d'un déploiement soient encore délivrés. */
class ContactMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public string $name,
        public string $email,
        public string $topic,
        public string $body,
        public string $lang,
        public ?string $ip,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[Contact] '.$this->topic,
            replyTo: [new Address($this->email, $this->name)],
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.contact');
    }
}
