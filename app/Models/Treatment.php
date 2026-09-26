<?php

namespace App\Models;

use App\Domain\Booking\QuoteBuilder;
use App\Domain\Catalogue\Presentation;
use App\Models\Concerns\Translatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Treatment extends Model
{
    use Translatable;

    public const CATEGORIES = ['hammam', 'massage', 'face', 'ritual', 'other'];

    protected $guarded = [];

    protected $casts = ['price_solo' => 'float', 'price_couple' => 'float', 'price_group' => 'float', 'included' => 'array'];

    /** Une seule formule mise en avant par établissement : poser un badge retire celui des autres. */
    protected static function booted(): void
    {
        static::saving(function (self $t) {
            if ($t->featured_badge !== null && ! in_array($t->featured_badge, Presentation::BADGES, true)) {
                $t->featured_badge = null;
            }
            if ($t->included !== null) {
                $t->included = Presentation::cleanIncluded($t->included) ?: null;
            }
        });
        static::saved(function (self $t) {
            if ($t->featured_badge && ($t->wasRecentlyCreated || $t->wasChanged('featured_badge'))) {
                static::where('spa_id', $t->spa_id)->whereKeyNot($t->getKey())->whereNotNull('featured_badge')->update(['featured_badge' => null]);
            }
        });
    }

    public function spa(): BelongsTo
    {
        return $this->belongsTo(Spa::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(TreatmentStep::class)->orderBy('position');
    }

    public function extras(): HasMany
    {
        return $this->hasMany(Extra::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isPackage(): bool
    {
        return $this->steps->count() > 1;
    }

    /** Durée totale déduite des étapes (max offset + durée), ou duration_min si aucune étape. */
    public function computedDuration(): int
    {
        $end = 0;
        foreach ($this->steps as $s) {
            $end = max($end, $s->offset_min + $s->duration_min);
        }

        return $end ?: (int) $this->duration_min;
    }

    /** Formule tarifaire retenue et prix unitaire par personne : [formula, unit]. */
    public function formulaFor(int $party): array
    {
        $p = QuoteBuilder::treatmentPrice($this, $party);

        return [$p['formula'], (float) $p['unit']];
    }
}
