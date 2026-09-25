<?php

namespace App\Models;

use App\Models\Concerns\Translatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Spa extends Model
{
    use Translatable;

    public const STATUSES = ['draft', 'pending', 'published', 'suspended'];

    protected $guarded = [];

    protected $casts = ['submitted_at' => 'datetime', 'published_at' => 'datetime', 'lat' => 'float', 'lng' => 'float', 'rating' => 'float', 'price_from' => 'float'];

    protected static function booted(): void
    {
        static::saving(function (self $spa) {
            if ($spa->city_id && $spa->isDirty('city_id')) {
                $spa->city = City::find($spa->city_id)?->name_fr ?? $spa->city;
            }
        });
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function cityRef(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'spa_categories');
    }

    public function amenities(): BelongsToMany
    {
        return $this->belongsToMany(Amenity::class, 'spa_amenities');
    }

    /** Libellés traduits des expériences puis équipements, pour les fiches publiques. */
    public function featureLabels(): array
    {
        return $this->categories->where('is_active', true)->sortBy('sort_order')->map->label()
            ->merge($this->amenities->where('is_active', true)->sortBy('sort_order')->map->label())->values()->all();
    }

    public function whatsappNumber(): ?string
    {
        return $this->whatsapp ?: $this->phone;
    }

    public function isOnboarding(): bool
    {
        return $this->onboarding_step !== null;
    }

    public function photos(): HasMany
    {
        return $this->hasMany(SpaPhoto::class)->orderBy('sort_order');
    }

    public function hours(): HasMany
    {
        return $this->hasMany(SpaHour::class)->orderBy('weekday')->orderBy('opens_min');
    }

    public function resourceTypes(): HasMany
    {
        return $this->hasMany(ResourceType::class)->orderBy('sort_order');
    }

    public function resources(): HasMany
    {
        return $this->hasMany(Resource::class)->orderBy('sort_order');
    }

    public function treatments(): HasMany
    {
        return $this->hasMany(Treatment::class)->orderBy('sort_order');
    }

    public function extras(): HasMany
    {
        return $this->hasMany(Extra::class);
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(Block::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function promotions(): HasMany
    {
        return $this->hasMany(Promotion::class);
    }

    public function scopePublished(Builder $q): Builder
    {
        return $q->where('status', 'published');
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function coverPhoto(): ?SpaPhoto
    {
        return $this->photos->firstWhere('is_cover', true) ?? $this->photos->first();
    }

    public function slotStep(): int
    {
        return $this->slot_step_minutes ?: (int) config('hl.slot_step_minutes');
    }

    public function minLead(): int
    {
        return $this->min_lead_minutes ?? (int) config('hl.min_lead_minutes');
    }

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
