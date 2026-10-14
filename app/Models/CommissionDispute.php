<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Contestation d'une commission par le partenaire. Ne modifie jamais le grand livre : une contestation ouverte (kind=dispute)
 * exclut seulement la réservation concernée du relevé facturable jusqu'à la décision de HammamLuxury.
 */
class CommissionDispute extends Model
{
    public const KINDS = ['dispute', 'correction_request'];

    public const STATUSES = ['open', 'accepted', 'rejected'];

    public const REASONS = ['service_not_performed', 'amount_incorrect', 'free_cancellation', 'fee_not_charged', 'duplicate', 'other'];

    protected $guarded = [];

    protected $casts = ['decided_at' => 'immutable_datetime'];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function spa(): BelongsTo
    {
        return $this->belongsTo(Spa::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function notifications(): MorphMany
    {
        return $this->morphMany(BillingNotification::class, 'notifiable');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /** Contestation bloquante encore ouverte : la ligne est retirée du relevé facturable. */
    public function blocksStatement(): bool
    {
        return $this->isOpen() && $this->kind === 'dispute';
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('status', 'open');
    }

    public function scopeBlocking(Builder $q): Builder
    {
        return $q->open()->where('kind', 'dispute');
    }
}
