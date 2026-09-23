<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use App\Domain\Booking\QuoteBuilder;
use App\Models\Concerns\Translatable;

class Treatment extends Model
{
    use Translatable;

    public const CATEGORIES = ['hammam', 'massage', 'face', 'ritual', 'other'];

    protected $guarded = [];

    protected $casts = ['price_solo' => 'float', 'price_couple' => 'float', 'price_group' => 'float'];

    public function spa(): BelongsTo { return $this->belongsTo(Spa::class); }
    public function steps(): HasMany { return $this->hasMany(TreatmentStep::class)->orderBy('position'); }
    public function extras(): HasMany { return $this->hasMany(Extra::class); }

    public function isActive(): bool { return $this->status === 'active'; }
    public function isPackage(): bool { return $this->steps->count() > 1; }

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
