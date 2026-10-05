<?php

namespace App\Mail;

use App\Models\Review;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Invitation à donner son avis, envoyée le lendemain d'une prestation effectuée : établissement, invitation, un bouton. */
class ReviewInviteMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public Review $review) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('emails.review_invite.subject', ['spa' => $this->review->spa->name]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.review-invite', with: [
            'review' => $this->review,
            'booking' => $this->review->booking,
            'url' => route('review.form', ['locale' => $this->review->locale, 'token' => $this->review->token]),
        ]);
    }
}
