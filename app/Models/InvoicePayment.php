<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * Paiement reçu sur une facture émise (ou contre-passation d'un paiement saisi par erreur, montant négatif lié par
 * `reverses_payment_id`). Ajout seul : seules les colonnes de suivi du reçu PDF peuvent changer après création.
 */
class InvoicePayment extends Model
{
    public const METHOD_TRANSFER = 'bank_transfer';

    public const METHOD_CASH = 'cash';

    public const METHODS = ['bank_transfer', 'cash'];

    public const RECEIPT_PENDING = 'pending';

    public const RECEIPT_READY = 'ready';

    public const RECEIPT_FAILED = 'failed';

    /** Colonnes modifiables après création (suivi du reçu uniquement). */
    public const MUTABLE = ['receipt_status', 'receipt_path', 'receipt_sha256', 'receipt_attempts', 'receipt_error', 'updated_at'];

    protected $guarded = [];

    protected $casts = ['amount' => 'float', 'balance_after' => 'float', 'paid_on' => 'immutable_date', 'confirmed_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::updating(function (self $payment) {
            $illegal = array_diff(array_keys($payment->getDirty()), self::MUTABLE);
            if ($illegal !== []) {
                throw new LogicException('Invoice payments are append-only: '.implode(', ', $illegal));
            }
        });
        static::deleting(fn () => throw new LogicException('Invoice payments are append-only. Record a linked reversal.'));
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_payment_id');
    }

    public function reversedBy(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_payment_id');
    }

    public function overpayment(): HasOne
    {
        return $this->hasOne(InvoiceOverpayment::class, 'payment_id');
    }

    public function isReversal(): bool
    {
        return $this->reverses_payment_id !== null;
    }

    public function isCash(): bool
    {
        return $this->method === self::METHOD_CASH;
    }

    public function scopeCash(Builder $q): Builder
    {
        return $q->where('method', self::METHOD_CASH);
    }
}
