<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Message du formulaire Contact public, transmis à l'équipe HammamLuxury avec réponse directe à l'expéditeur. */
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
