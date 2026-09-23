<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use App\Models\Concerns\Translatable;
use Illuminate\Database\Eloquent\Builder;

class Spa extends Model
{
    use Translatable;

    public const STATUSES = ['draft', 'pending', 'published', 'suspended'];

    protected $guarded = [];

    protected $casts = ['features' => 'array', 'published_at' => 'datetime', 'lat' => 'float', 'lng' => 'float', 'rating' => 'float', 'price_from' => 'float'];

    public function partner(): BelongsTo { return $this->belongsTo(Partner::class); }
    public function photos(): HasMany { return $this->hasMany(SpaPhoto::class)->orderBy('sort_order'); }
    public function hours(): HasMany { return $this->hasMany(SpaHour::class)->orderBy('weekday')->orderBy('opens_min'); }
    public function resourceTypes(): HasMany { return $this->hasMany(ResourceType::class)->orderBy('sort_order'); }
    public function resources(): HasMany { return $this->hasMany(Resource::class)->orderBy('sort_order'); }
    public function treatments(): HasMany { return $this->hasMany(Treatment::class)->orderBy('sort_order'); }
    public function extras(): HasMany { return $this->hasMany(Extra::class); }
    public function blocks(): HasMany { return $this->hasMany(Block::class); }
    public function bookings(): HasMany { return $this->hasMany(Booking::class); }
    public function reviews(): HasMany { return $this->hasMany(Review::class); }
    public function promotions(): HasMany { return $this->hasMany(Promotion::class); }

    public function scopePublished(Builder $q): Builder { return $q->where('status', 'published'); }

    public function isPublished(): bool { return $this->status === 'published'; }

    public function coverPhoto(): ?SpaPhoto
    {
        return $this->photos->firstWhere('is_cover', true) ?? $this->photos->first();
    }

    public function slotStep(): int { return $this->slot_step_minutes ?: (int) config('hl.slot_step_minutes'); }
    public function minLead(): int { return $this->min_lead_minutes ?? (int) config('hl.min_lead_minutes'); }

    /** Plages d'ouverture d'un jour donné, en minutes depuis minuit : [[opens, closes], …]. */
    public function openingRanges(int $weekday): array
    {
        return $this->hours->where('weekday', $weekday)->map(fn ($h) => [(int) $h->opens_min, (int) $h->closes_min])->values()->all();
    }

    public function refreshPriceFrom(): void
    {
        $this->price_from = $this->treatments()->where('status', 'active')->min('price_solo');
        $this->save();
    }
}
