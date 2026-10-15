<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Excédent constaté sur un paiement : signalé à l'administrateur, jamais imputé automatiquement ; décision tracée (F4). */
class InvoiceOverpayment extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUSES = ['open', 'applied', 'refunded'];

    protected $guarded = [];

    protected $casts = ['amount' => 'float', 'decided_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::updating(function (self $o) {
            $illegal = array_diff(array_keys($o->getDirty()), ['status', 'decided_by', 'decided_at', 'decision_note', 'updated_at']);
            if ($illegal !== []) {
                throw new LogicException('Overpayments are append-only: '.implode(', ', $illegal));
            }
        });
        static::deleting(fn () => throw new LogicException('Overpayments cannot be deleted.'));
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(InvoicePayment::class, 'payment_id');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_OPEN);
    }
}
