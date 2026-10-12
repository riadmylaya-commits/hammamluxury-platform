<?php

namespace App\Models;

use App\Domain\Booking\ResourceProtection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Resource extends Model
{
    protected $guarded = [];

    protected $casts = ['archived_at' => 'datetime'];

    public function save(array $options = [])
    {
        return app(ResourceProtection::class)->saveResource($this, fn () => parent::save($options));
    }

    public function delete()
    {
        return app(ResourceProtection::class)->archiveResource($this);
    }

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
