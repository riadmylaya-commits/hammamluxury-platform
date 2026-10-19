<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Ligne d'avoir : une écriture de correction du grand livre au plus une fois (clé unique `ledger_entry_id`). Figée dès l'émission. */
class CreditNoteLine extends Model
{
    protected $guarded = [];

    protected $casts = ['amount' => 'float'];

    protected static function booted(): void
    {
        $frozen = fn (self $line) => $line->creditNote?->isIssued() ? throw new LogicException('Issued credit note lines are immutable.') : null;
        static::updating($frozen);
        static::deleting($frozen);
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class);
    }

    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(InvoiceLine::class);
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
