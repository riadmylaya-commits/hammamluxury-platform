<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Str;

class Booking extends Model
{
    public const STATUSES = ['waiting', 'confirmed', 'declined', 'cancelled', 'expired', 'completed', 'no_show'];

    /** Statuts qui ne consomment plus de capacité. */
    public const INACTIVE_STATUSES = ['declined', 'cancelled', 'expired', 'no_show'];

    protected $guarded = [];

    protected $casts = [
        'quote' => 'array',
        'start_at' => 'datetime', 'end_at' => 'datetime', 'expires_at' => 'datetime',
        'expiration_notified_at' => 'datetime', 'confirmed_at' => 'datetime', 'cancelled_at' => 'datetime',
        'total' => 'float', 'commission_pct' => 'float', 'commission_amount' => 'float',
    ];

    protected static function booted(): void
    {
        static::creating(function (Booking $b) {
            $b->reference ??= self::newReference();
            $b->manage_token ??= Str::random(48);
        });
    }

    public static function newReference(): string
    {
        do {
            $ref = 'HL'.strtoupper(Str::random(6));
        } while (self::where('reference', $ref)->exists());

        return $ref;
    }

    public function spa(): BelongsTo { return $this->belongsTo(Spa::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function participants(): HasMany { return $this->hasMany(BookingParticipant::class)->orderBy('participant_no'); }
    public function allocations(): HasMany { return $this->hasMany(Allocation::class); }
    public function events(): HasMany { return $this->hasMany(BookingEvent::class); }

    public function isActive(): bool { return ! in_array($this->status, self::INACTIVE_STATUSES, true); }
    public function isWaiting(): bool { return $this->status === 'waiting'; }
    public function isConfirmed(): bool { return $this->status === 'confirmed'; }

    public function customerName(): string { return trim($this->first_name.' '.$this->last_name); }

    public function log(string $type, ?string $actor = null, array $payload = []): BookingEvent
    {
        return $this->events()->create(['type' => $type, 'actor' => $actor, 'payload' => $payload ?: null]);
    }
}
