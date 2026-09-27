<?php

namespace App\Models;

use App\Models\Concerns\Translatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResourceType extends Model
{
    use Translatable;

    public const MODES = ['pool', 'unit'];

    public const KINDS = ['room', 'therapist', 'equipment'];

    protected $guarded = [];

    public function spa(): BelongsTo
    {
        return $this->belongsTo(Spa::class);
    }

    public function resources(): HasMany
    {
        return $this->hasMany(Resource::class)->orderBy('sort_order');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(TreatmentStep::class);
    }

    public function isUnit(): bool
    {
        return $this->allocation_mode === 'unit';
    }

    /** Famille de ressource (hammam|massage|soin|other) déduite du slug ou du nom, pour adapter les textes d'aide. */
    public static function familyOf(?string $slug, ?string $name = null): string
    {
        $s = mb_strtolower((string) $slug.' '.(string) $name);
        if (str_contains($s, 'hammam')) {
            return 'hammam';
        }
        if (str_contains($s, 'massage') || str_contains($s, 'cabine') || str_contains($s, 'cabin')) {
            return 'massage';
        }
        if (str_contains($s, 'soin') || str_contains($s, 'treatment') || str_contains($s, 'salle')) {
            return 'soin';
        }

        return 'other';
    }

    public function family(): string
    {
        return self::familyOf($this->slug, $this->name_fr);
    }
}
