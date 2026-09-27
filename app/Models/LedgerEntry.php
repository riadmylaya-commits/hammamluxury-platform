<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerEntry extends Model
{
    protected $guarded = [];

    protected $casts = ['amount' => 'float'];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
