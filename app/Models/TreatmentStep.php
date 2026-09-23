<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class TreatmentStep extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function treatment(): BelongsTo { return $this->belongsTo(Treatment::class); }
    public function resourceType(): BelongsTo { return $this->belongsTo(ResourceType::class); }
}
