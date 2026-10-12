<?php

namespace App\Models;

use App\Domain\Media\PhotoPathPolicy;
use App\Models\Concerns\Translatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpaPhoto extends Model
{
    use Translatable;

    protected $guarded = [];

    protected $casts = ['is_cover' => 'bool'];

    protected static function booted(): void
    {
        static::saving(function (self $photo) {
            $spa = Spa::findOrFail($photo->spa_id);
            PhotoPathPolicy::assertCanManage($spa);
            PhotoPathPolicy::assertCanAttach($spa, $photo->path);
        });
    }

    public function spa(): BelongsTo
    {
        return $this->belongsTo(Spa::class);
    }

    public function url(): string
    {
        // Unsafe legacy values remain untouched in storage but are never exposed as URLs.
        if (! PhotoPathPolicy::isSafe((string) $this->path)) {
            return '';
        }

        return PhotoPathPolicy::isExternal($this->path) ? $this->path : asset('storage/'.$this->path);
    }
}
