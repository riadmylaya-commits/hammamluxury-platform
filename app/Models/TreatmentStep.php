<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TreatmentStep extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function treatment(): BelongsTo
    {
        return $this->belongsTo(Treatment::class);
    }

    public function resourceType(): BelongsTo
    {
        return $this->belongsTo(ResourceType::class);
    }

    /** Libellé affiché au client : texte libre du partenaire, sinon nom du type de ressource. */
    public function displayLabel(): string
    {
        return filled($this->label) ? $this->label : (string) $this->resourceType?->tr('name');
    }
}
