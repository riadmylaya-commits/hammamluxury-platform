<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Trace par destinataire d'un e-mail financier (relevé, contestation) : prévu → mis en file → envoyé | échoué. */
class BillingNotification extends Model
{
    public const STATUSES = ['planned', 'queued', 'sent', 'failed'];

    public const EVENTS = ['statement_available', 'dispute_opened', 'dispute_decided', 'invoice_issued', 'invoice_pdf_failed'];

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
