<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Trace par destinataire d'un e-mail de réservation : prévu → mis en file → envoyé | échoué. */
class BookingNotification extends Model
{
    public const STATUSES = ['planned', 'queued', 'sent', 'failed'];

    protected $guarded = [];

    protected $casts = [
        'queued_at' => 'datetime',
        'sent_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
