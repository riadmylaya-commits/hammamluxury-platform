<?php

namespace App\Models;

use App\Domain\Billing\BillingGate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class LedgerEntry extends Model
{
    public const COMMISSION_TYPES = ['commission', 'commission_reversal', 'commission_adjust'];

    public const SCOPE_LEGACY = 'legacy';

    public const SCOPE_BILLABLE = 'billable';

    protected $guarded = [];

    protected $casts = ['amount' => 'float'];

    protected static function booted(): void
    {
        // Après la qualification initiale (hl:billing-mark-legacy --apply), chaque nouvelle écriture est qualifiée à sa création :
        // une correction suit la commission initiale de sa chaîne, les autres suivent leur date. Avant, rien n'est qualifié d'office.
        static::creating(function (self $entry) {
            if ($entry->billing_scope === null && ActivityLog::where('action', 'billing.scope_marked')->exists()) {
                $entry->billing_scope = app(BillingGate::class)->scopeFor($entry);
            }
        });
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

    public function spa(): BelongsTo
    {
        return $this->belongsTo(Spa::class);
    }
}
