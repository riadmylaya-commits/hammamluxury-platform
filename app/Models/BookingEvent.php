<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['payload' => 'array', 'created_at' => 'datetime'];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
