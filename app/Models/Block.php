<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Block extends Model
{
    public const SCOPES = ['spa', 'type', 'resource'];

    public const KINDS = ['closed', 'holiday', 'maintenance', 'private', 'other'];

    protected $guarded = [];

    protected $casts = ['start_at' => 'datetime', 'end_at' => 'datetime'];

    public function spa(): BelongsTo
    {
        return $this->belongsTo(Spa::class);
    }

    public function resourceType(): BelongsTo
    {
        return $this->belongsTo(ResourceType::class);
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }
}
