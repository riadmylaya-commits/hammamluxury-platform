<?php

namespace App\Models;

use App\Domain\Media\PhotoPathPolicy;
use App\Models\Concerns\Translatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SpaPhoto extends Model
{
    use Translatable;

    /** Disque privé (hors dossier public) : les fichiers ne sont servis que par la route `spa.photo`. */
    public const DISK = 'local';

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

        return PhotoPathPolicy::isExternal($this->path) ? $this->path : route('spa.photo', $this);
    }

    /** Visible de tous dès que la fiche est publiée (partenaire approuvé) ; sinon réservée à l'Admin et au propriétaire. */
    public function isPubliclyVisible(): bool
    {
        $spa = $this->spa;

        return $spa !== null && $spa->status === 'published' && $spa->partner?->status === 'approved';
    }

    /** Disque qui contient réellement le fichier : privé, ou public pour un fichier non encore migré. */
    public function storedDisk(): ?string
    {
        foreach ([self::DISK, 'public'] as $disk) {
            if (Storage::disk($disk)->exists($this->path)) {
                return $disk;
            }
        }

        return null;
    }
}
