<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** Photo jointe à un avis : stockée en privé jusqu'à publication, puis copiée sur le disque public. */
class ReviewPhoto extends Model
{
    protected $guarded = [];

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    public function isPublic(): bool
    {
        return $this->disk === 'public';
    }

    /** URL publique (avis publié) ou null tant que la photo est privée. */
    public function publicUrl(): ?string
    {
        return $this->isPublic() ? Storage::disk('public')->url($this->path) : null;
    }
}
