<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Resource extends Model
{
    protected $guarded = [];

    public function spa(): BelongsTo
    {
        return $this->belongsTo(Spa::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(ResourceType::class, 'resource_type_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class);
    }
}
