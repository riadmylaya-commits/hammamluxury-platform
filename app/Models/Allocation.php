<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Allocation extends Model
{
    protected $guarded = [];

    protected $casts = ['start_at' => 'datetime', 'end_at' => 'datetime'];

    public function booking(): BelongsTo { return $this->belongsTo(Booking::class); }
    public function resource(): BelongsTo { return $this->belongsTo(Resource::class); }
    public function resourceType(): BelongsTo { return $this->belongsTo(ResourceType::class); }
    public function participant(): BelongsTo { return $this->belongsTo(BookingParticipant::class, 'booking_participant_id'); }
}
