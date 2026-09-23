<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class BookingParticipant extends Model
{
    protected $guarded = [];

    protected $casts = ['extras' => 'array', 'price' => 'float'];

    public function booking(): BelongsTo { return $this->belongsTo(Booking::class); }
    public function treatment(): BelongsTo { return $this->belongsTo(Treatment::class); }
    public function allocations(): HasMany { return $this->hasMany(Allocation::class); }
}
