<?php

namespace App\Models;

use App\Domain\Media\PhotoPathPolicy;
use App\Models\Concerns\Translatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class SpaPhoto extends Model
{
    use Translatable;

    protected $guarded = [];

    protected $casts = ['is_cover' => 'bool'];

    protected static function booted(): void
    {
        // Signed-in context (forms, Livewire): full ownership policy. Trusted context without a
        // user (seeders, console, fixtures): the path must still be safe.
        static::saving(function (self $photo) {
            if (! is_string($photo->path) || ! PhotoPathPolicy::isSafe($photo->path)) {
                throw ValidationException::withMessages(['path' => __('partner.photo_path_error')]);
            }
            if (auth()->check()) {
                $spa = Spa::findOrFail($photo->spa_id);
                PhotoPathPolicy::assertCanManage($spa);
                PhotoPathPolicy::assertCanAttach($spa, $photo->path);
            }
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
