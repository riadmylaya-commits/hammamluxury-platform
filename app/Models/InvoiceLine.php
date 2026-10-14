<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Ligne de facture : une écriture du grand livre au plus une fois (clé unique `ledger_entry_id`). Figée dès l'émission. */
class InvoiceLine extends Model
{
    protected $guarded = [];

    protected $casts = ['service_at' => 'immutable_datetime', 'base_amount' => 'float', 'commission_pct' => 'float', 'amount' => 'float', 'carried' => 'bool'];

    protected static function booted(): void
    {
        $frozen = fn (self $line) => $line->invoice?->isIssued() ? throw new LogicException('Issued invoice lines are immutable.') : null;
        static::updating($frozen);
        static::deleting($frozen);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function spa(): BelongsTo
    {
        return $this->belongsTo(Spa::class);
    }
}
