<?php

namespace App\Mail;

use App\Models\Spa;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Invitation du propriétaire d'un établissement : lien de définition du mot de passe de l'espace partenaire. */
class PartnerInviteMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public User $user, public Spa $spa, public string $token) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('emails.partner_invite.subject', ['spa' => $this->spa->name]));
    }

    public function content(): Content
    {
        $url = Filament::getPanel('partner')->getResetPasswordUrl($this->token, $this->user);

        return new Content(markdown: 'emails.partner-invite', with: [
            'user' => $this->user,
            'spa' => $this->spa,
            'url' => $url,
            'hours' => (int) round(config('auth.passwords.partners.expire', 1440) / 60),
        ]);
    }
}
