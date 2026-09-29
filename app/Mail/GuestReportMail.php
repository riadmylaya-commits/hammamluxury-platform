<?php

namespace App\Mail;

use App\Models\ClientIncident;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Alerte administration : un établissement a signalé un comportement client. */
class GuestReportMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public ClientIncident $incident) {}

    public function envelope(): Envelope
    {
        $b = $this->incident->booking;

        return new Envelope(subject: __('emails.guest_report.subject', ['ref' => $b->reference, 'spa' => $b->spa->name]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.guest-report', with: [
            'incident' => $this->incident,
            'booking' => $this->incident->booking,
            'url' => url('/admin/client-incidents/'.$this->incident->id),
        ]);
    }
}
