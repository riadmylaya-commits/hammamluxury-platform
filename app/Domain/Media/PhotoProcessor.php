<?php

namespace App\Domain\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Photos d'établissement : vérification stricte (type réel, taille, dimensions),
 * puis ré-encodage JPEG redimensionné (métadonnées EXIF supprimées, aucun fichier d'origine conservé).
 */
final class PhotoProcessor
{
    public const MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public const MAX_BYTES = 12 * 1024 * 1024;

    public const MIN_WIDTH = 800;

    public const MIN_HEIGHT = 600;

    public const MAX_EDGE = 1600;

    public const QUALITY = 82;

    /** @return list<string> règles Laravel côté formulaire */
    public static function rules(): array
    {
        return ['image', 'mimes:jpg,jpeg,png,webp', 'max:'.(self::MAX_BYTES / 1024), 'dimensions:min_width='.self::MIN_WIDTH.',min_height='.self::MIN_HEIGHT];
    }

    /** Enregistre la version traitée sur le disque public et retourne son chemin relatif. */
    public static function store(UploadedFile $file, string $directory = 'spas', string $disk = 'public'): string
    {
        $image = self::process($file->getRealPath());
        $path = trim($directory, '/').'/'.Str::uuid().'.jpg';
        Storage::disk($disk)->put($path, $image, 'public');

        return $path;
    }

    /** Retourne le JPEG traité (binaire). Lève InvalidArgumentException si le fichier n'est pas une image acceptable. */
    public static function process(string $realPath): string
    {
        if (! is_file($realPath) || filesize($realPath) > self::MAX_BYTES) {
            throw new InvalidArgumentException(__('partner.photo_too_large'));
        }
        $info = @getimagesize($realPath);
        if (! $info || ! in_array($info['mime'], self::MIMES, true)) {
            throw new InvalidArgumentException(__('partner.photo_bad_type'));
        }
        [$w, $h] = $info;
        if ($w < self::MIN_WIDTH || $h < self::MIN_HEIGHT) {
            throw new InvalidArgumentException(__('partner.photo_too_small', ['w' => self::MIN_WIDTH, 'h' => self::MIN_HEIGHT]));
        }

        $src = match ($info['mime']) {
            'image/jpeg' => imagecreatefromjpeg($realPath),
            'image/png' => imagecreatefrompng($realPath),
            'image/webp' => imagecreatefromwebp($realPath),
        };
        if (! $src) {
            throw new InvalidArgumentException(__('partner.photo_bad_type'));
        }

        if ($info['mime'] === 'image/jpeg' && function_exists('exif_read_data')) {
            $src = self::applyOrientation($src, $realPath);
            $w = imagesx($src);
            $h = imagesy($src);
        }

        $scale = min(1, self::MAX_EDGE / max($w, $h));
        $tw = max(1, (int) round($w * $scale));
        $th = max(1, (int) round($h * $scale));

        $dst = imagecreatetruecolor($tw, $th);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefill($dst, 0, 0, $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
        imagedestroy($src);

        ob_start();
        imagejpeg($dst, null, self::QUALITY);
        imagedestroy($dst);

        return (string) ob_get_clean();
    }

    private static function applyOrientation(\GdImage $img, string $path): \GdImage
    {
        $exif = @exif_read_data($path);
        $orientation = (int) ($exif['Orientation'] ?? 1);
        $rotated = match ($orientation) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => null,
        };

        return $rotated ?: $img;
    }
}
