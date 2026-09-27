<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Message de la conversation client ↔ établissement rattachée à une réservation. */
class BookingMessage extends Model
{
    public const SENDERS = ['client', 'partner', 'admin'];

    protected $guarded = [];

    protected $casts = ['read_at' => 'datetime', 'notified_at' => 'datetime'];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Messages non lus par `$reader` (client | partner) : ceux envoyés par l'autre partie. */
    public function scopeUnreadFor(Builder $q, string $reader): Builder
    {
        return $q->whereNull('read_at')->where('sender', $reader === 'partner' ? 'client' : 'partner');
    }
}
