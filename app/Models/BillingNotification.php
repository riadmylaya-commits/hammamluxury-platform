<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Trace par destinataire d'un e-mail financier (relevé, contestation) : prévu → mis en file → envoyé | échoué. */
class BillingNotification extends Model
{
    public const STATUSES = ['planned', 'queued', 'sent', 'failed'];

    public const EVENTS = ['statement_available', 'dispute_opened', 'dispute_decided', 'invoice_issued', 'invoice_pdf_failed',
        'payment_partial', 'payment_received', 'overpayment_detected', 'receipt_pdf_failed',
        'reminder_before_due', 'reminder_due', 'reminder_late_1', 'reminder_late_2', 'invoice_late',
        'credit_note_issued', 'credit_note_pdf_failed', 'credit_applied', 'credit_refunded', 'correction_request_opened', 'corrections_overdue'];

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'queued_at' => 'datetime',
        'sent_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }
}
