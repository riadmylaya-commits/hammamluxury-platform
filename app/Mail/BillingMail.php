<?php

namespace App\Mail;

use App\Models\BillingNotification;
use App\Models\CommissionDispute;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Partner;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Un seul mailable paramétré par la trace de notification (statement_available, dispute_opened, dispute_decided). */
class BillingMail extends Mailable
{
    use SerializesModels;

    public function __construct(public BillingNotification $notification) {}

    public function envelope(): Envelope
    {
        $n = $this->notification;

        return new Envelope(subject: __('emails.billing.'.$n->event.'.subject', $this->vars()));
    }

    public function content(): Content
    {
        $n = $this->notification;
        $subject = $n->notifiable;

        return new Content(markdown: 'emails.billing', with: [
            'notification' => $n,
            'vars' => $this->vars(),
            'dispute' => $subject instanceof CommissionDispute ? $subject : null,
            'invoice' => $subject instanceof Invoice ? $subject : ($subject instanceof CreditNote ? $subject->invoice : null),
            'note' => $subject instanceof CreditNote ? $subject : null,
            'partner' => $subject instanceof Partner ? $subject : ($subject instanceof CommissionDispute ? $subject->partner : null),
        ]);
    }

    /** @return array<string, string> */
    private function vars(): array
    {
        $n = $this->notification;
        $p = $n->payload ?? [];

        return [
            'period' => (string) ($p['period'] ?? ''),
            'number' => (string) ($p['number'] ?? ''),
            'due' => (string) ($p['due'] ?? ''),
            'error' => (string) ($p['error'] ?? ''),
            'total' => number_format((float) ($p['total'] ?? 0), 2, ',', ' ').' '.config('hl.currency'),
            'amount' => number_format((float) ($p['amount'] ?? 0), 2, ',', ' ').' '.config('hl.currency'),
            'balance' => number_format((float) ($p['balance'] ?? 0), 2, ',', ' ').' '.config('hl.currency'),
            'paid_on' => (string) ($p['paid_on'] ?? ''),
            'method' => isset($p['method']) ? __('billing.method_'.$p['method']) : '',
            'receipt' => (string) ($p['receipt'] ?? ''),
            'receipt_line' => ! empty($p['receipt']) ? __('emails.billing.receipt_line', ['receipt' => $p['receipt']]) : '',
            'booking' => (string) ($p['booking'] ?? ($n->notifiable instanceof CommissionDispute ? $n->notifiable->booking?->reference : '')),
            'decision' => isset($p['decision']) ? __('billing.dispute_status_'.$p['decision']) : '',
            'invoice' => (string) ($p['invoice'] ?? ''),
            'reverses' => (string) ($p['reverses'] ?? ''),
            'source' => (string) ($p['source'] ?? ''),
            'remaining' => number_format((float) ($p['remaining'] ?? 0), 2, ',', ' ').' '.config('hl.currency'),
            'refund_on' => (string) ($p['refund_on'] ?? ''),
            'reference' => (string) ($p['reference'] ?? ''),
            'age' => (string) ($p['age'] ?? ''),
            'due_days' => (string) ($p['due'] ?? ''),
            'partner' => (string) ($n->notifiable instanceof CommissionDispute ? $n->notifiable->partner?->company_name : ($n->notifiable instanceof Partner ? $n->notifiable->company_name : ($n->notifiable instanceof Invoice || $n->notifiable instanceof CreditNote ? (string) ($p['partner'] ?? $n->notifiable->partner_snapshot['company_name'] ?? $n->notifiable->partner?->company_name) : ''))),
        ];
    }
}
