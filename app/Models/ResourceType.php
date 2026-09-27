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
}
