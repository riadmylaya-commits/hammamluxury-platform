<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Partner extends Model
{
    protected $guarded = [];

    protected $casts = ['commission_pct' => 'float'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function spas(): HasMany
    {
        return $this->hasMany(Spa::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function commissionPct(): float
    {
        return $this->commission_pct ?? (float) config('hl.default_commission_pct');
    }
}
