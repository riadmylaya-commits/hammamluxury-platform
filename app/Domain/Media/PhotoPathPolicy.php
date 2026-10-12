<?php

namespace App\Domain\Media;

use App\Models\Spa;
use App\Models\SpaPhoto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Validate both URL syntax and ownership; a random filename is not proof of ownership. */
final class PhotoPathPolicy
{
    public static function isSafe(string $path): bool
    {
        if ($path === '' || strlen($path) > 255 || preg_match('/[\x00-\x20\x7f"\'<>\\\\]/', $path)) {
            return false;
        }

        if (preg_match('#^https?://#i', $path)) {
            $parts = parse_url($path);

            return filter_var($path, FILTER_VALIDATE_URL) !== false
                && $parts !== false && ! empty($parts['host'])
                && ! isset($parts['user']) && ! isset($parts['pass']);
        }

        return preg_match('#^spas/[A-Za-z0-9_./-]+$#D', $path) === 1
            && preg_match('#(^|/)\.\.?($|/)#', $path) !== 1;
    }

    public static function isExternal(string $path): bool
    {
        return preg_match('#^https?://#i', $path) === 1;
    }

    public static function assertCanManage(Spa $spa): void
    {
        $user = auth()->user();
        if (! $spa->exists || ! $user || ! $user->canAccessTenant($spa)) {
            throw ValidationException::withMessages(['path' => __('partner.photo_owner_error')]);
        }
    }

    public static function assertCanAttach(Spa $spa, mixed $path, string $field = 'path'): void
    {
        if (! is_string($path) || ! self::isSafe($path)) {
            throw ValidationException::withMessages([$field => __('partner.photo_path_error')]);
        }

        // Keep safe legacy local/external photos already attached to this exact spa.
        // Never accept a new external URL or a path belonging to another spa.
        if (SpaPhoto::query()->where('spa_id', $spa->id)->where('path', $path)->exists()) {
            return;
        }

        if (self::isExternal($path)
            || ! DB::table('spa_photo_uploads')->where('spa_id', $spa->id)->where('path', $path)->exists()
            || ! Storage::disk('public')->exists($path)) {
            throw ValidationException::withMessages([$field => __('partner.photo_owner_error')]);
        }
    }
}
