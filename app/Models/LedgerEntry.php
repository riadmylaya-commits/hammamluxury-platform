<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class LedgerEntry extends Model
{
    public const COMMISSION_TYPES = ['commission', 'commission_reversal', 'commission_adjust'];

    protected $guarded = [];

    protected $casts = ['amount' => 'float'];

    protected static function booted(): void
    {
        // Une correction ajoute une écriture liée ; ne jamais réécrire l'historique financier.
        static::updating(fn () => throw new LogicException('Ledger entries are append-only. Add a linked correction entry.'));
        static::deleting(fn () => throw new LogicException('Ledger entries are append-only. Add a linked correction entry.'));
    }

    public function correctsEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_entry_id');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
