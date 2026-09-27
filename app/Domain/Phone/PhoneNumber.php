<?php

namespace App\Domain\Phone;

use Illuminate\Http\Request;

/**
 * Numéros de téléphone au format international E.164 (ex. +212661351989).
 * Pays proposés dans les sélecteurs, détection du pays par défaut, normalisation et découpage.
 */
final class PhoneNumber
{
    public const DEFAULT_COUNTRY = 'MA';

    /** code ISO => [indicatif, longueur nationale min, max] */
    private const COUNTRIES = [
        'MA' => ['212', 9, 9],
        'FR' => ['33', 9, 9],
        'ES' => ['34', 9, 9],
        'GB' => ['44', 9, 10],
        'BE' => ['32', 8, 9],
        'CH' => ['41', 9, 9],
        'IT' => ['39', 6, 11],
        'DE' => ['49', 5, 12],
        'NL' => ['31', 9, 9],
        'PT' => ['351', 9, 9],
        'DZ' => ['213', 9, 9],
        'TN' => ['216', 8, 8],
        'AE' => ['971', 8, 9],
        'SA' => ['966', 8, 9],
        'QA' => ['974', 8, 8],
        'US' => ['1', 10, 10],
        'CA' => ['1', 10, 10],
    ];

    /** Correspondance langue/région du navigateur => pays. */
    private const LOCALE_COUNTRIES = ['fr-ma' => 'MA', 'ar-ma' => 'MA', 'fr-fr' => 'FR', 'fr-be' => 'BE', 'fr-ch' => 'CH', 'es' => 'ES', 'en-gb' => 'GB', 'en-ca' => 'CA', 'fr-ca' => 'CA', 'it' => 'IT', 'de' => 'DE', 'nl' => 'NL', 'pt' => 'PT', 'ar-dz' => 'DZ', 'ar-tn' => 'TN', 'ar-ae' => 'AE', 'ar-sa' => 'SA', 'ar-qa' => 'QA'];

    /** @return array<string, string> code ISO => "🇲🇦 +212 Maroc", trié par nom dans la locale courante. */
    public static function options(?string $locale = null): array
    {
        $out = [];
        foreach (self::COUNTRIES as $iso => [$dial]) {
            $out[$iso] = self::flag($iso).' +'.$dial.' '.self::countryName($iso, $locale);
        }
        $order = ['MA', 'FR', 'ES', 'GB'];
        uksort($out, function (string $a, string $b) use ($order, $locale): int {
            $ia = array_search($a, $order, true);
            $ib = array_search($b, $order, true);
            if ($ia !== false || $ib !== false) {
                return ($ia === false ? 99 : $ia) <=> ($ib === false ? 99 : $ib);
            }

            return strcoll(self::countryName($a, $locale), self::countryName($b, $locale));
        });

        return $out;
    }

    public static function dialCode(string $iso): string
    {
        return self::COUNTRIES[strtoupper($iso)][0] ?? self::COUNTRIES[self::DEFAULT_COUNTRY][0];
    }

    public static function flag(string $iso): string
    {
        $iso = strtoupper($iso);

        return mb_chr(0x1F1E6 + ord($iso[0]) - 65).mb_chr(0x1F1E6 + ord($iso[1]) - 65);
    }

    public static function countryName(string $iso, ?string $locale = null): string
    {
        $locale = str_starts_with((string) ($locale ?? app()->getLocale()), 'en') ? 'en' : 'fr';

        return __('phone.'.$iso, [], $locale);
    }

    /** Pays par défaut d'après la langue du navigateur ; l'utilisateur reste libre de le changer. */
    public static function detectCountry(?Request $request = null): string
    {
        $request ??= request();
        $header = strtolower((string) $request->header('Accept-Language', ''));
        foreach (explode(',', $header) as $part) {
            $tag = trim(explode(';', $part)[0]);
            if ($tag === '') {
                continue;
            }
            if (isset(self::LOCALE_COUNTRIES[$tag])) {
                return self::LOCALE_COUNTRIES[$tag];
            }
            $lang = explode('-', $tag)[0];
            if (isset(self::LOCALE_COUNTRIES[$lang])) {
                return self::LOCALE_COUNTRIES[$lang];
            }
        }

        return self::DEFAULT_COUNTRY;
    }

    /**
     * Retourne le numéro au format E.164 ou null si invalide.
     * Accepte "0661351989" (national), "661 35 19 89", "+212661351989", "00212…".
     */
    public static function normalize(?string $raw, ?string $iso = null): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        $iso = strtoupper($iso ?: self::DEFAULT_COUNTRY);
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        $international = str_starts_with($raw, '+') || str_starts_with($digits, '00');
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if ($international) {
            $e164 = '+'.$digits;
        } else {
            $dial = self::dialCode($iso);
            $national = ltrim($digits, '0');
            if (str_starts_with($digits, $dial) && strlen($digits) > strlen($dial) + 5) {
                $e164 = '+'.$digits;
            } else {
                $e164 = '+'.$dial.$national;
            }
        }

        return self::isValid($e164) ? $e164 : null;
    }

    public static function isValid(?string $e164): bool
    {
        if (! is_string($e164) || ! preg_match('/^\+[1-9]\d{6,14}$/', $e164)) {
            return false;
        }
        $iso = self::countryOf($e164);
        if ($iso === null) {
            return true;
        }
        [$dial, $min, $max] = self::COUNTRIES[$iso];
        $len = strlen($e164) - 1 - strlen($dial);

        return $len >= $min && $len <= $max;
    }

    /** Code ISO du pays d'un numéro E.164 (pays connu) ou null. */
    public static function countryOf(?string $e164): ?string
    {
        if (! is_string($e164) || ! str_starts_with($e164, '+')) {
            return null;
        }
        $best = null;
        foreach (self::COUNTRIES as $iso => [$dial]) {
            if (str_starts_with(substr($e164, 1), $dial) && ($best === null || strlen($dial) > strlen(self::dialCode($best)))) {
                $best = $iso;
            }
        }

        return $best;
    }

    /** Partie nationale d'un numéro E.164 (sans indicatif), pour préremplir un champ. */
    public static function nationalPart(?string $e164): string
    {
        $iso = self::countryOf($e164);
        if ($iso === null) {
            return is_string($e164) ? ltrim($e164, '+') : '';
        }

        return substr((string) $e164, 1 + strlen(self::dialCode($iso)));
    }

    /** Affichage lisible : +212 6 61 35 19 89. */
    public static function format(?string $e164): string
    {
        if (! self::isValid($e164)) {
            return (string) $e164;
        }
        $iso = self::countryOf($e164);
        if ($iso === null) {
            return (string) $e164;
        }
        $national = self::nationalPart($e164);
        $head = strlen($national) % 2 === 1 ? substr($national, 0, 1).' ' : '';
        $rest = strlen($national) % 2 === 1 ? substr($national, 1) : $national;

        return '+'.self::dialCode($iso).' '.$head.trim(chunk_split($rest, 2, ' '));
    }

    public static function whatsappUrl(?string $e164): ?string
    {
        return self::isValid($e164) ? 'https://wa.me/'.substr((string) $e164, 1) : null;
    }
}
