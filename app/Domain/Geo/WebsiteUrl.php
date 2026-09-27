<?php

namespace App\Domain\Geo;

use Closure;

/** Adresse de site web saisie librement (« www.exemple.com ») → URL complète https:// ; null si vide ou invalide. */
final class WebsiteUrl
{
    public static function normalize(?string $input): ?string
    {
        $value = trim((string) $input);
        if ($value === '') {
            return null;
        }
        if (! preg_match('#^https?://#i', $value)) {
            $value = 'https://'.$value;
        }
        $value = preg_replace('#^http://#i', 'https://', $value);

        $host = parse_url($value, PHP_URL_HOST);
        if (! $host || ! str_contains($host, '.') || ! filter_var($value, FILTER_VALIDATE_URL)) {
            return null;
        }

        return rtrim($value, '/');
    }

    public static function rule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (filled($value) && self::normalize((string) $value) === null) {
                $fail(__('partner.website_invalid'));
            }
        };
    }
}
