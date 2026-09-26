<?php

namespace App\Domain\Catalogue;

/**
 * Vocabulaire fermé utilisé pour la présentation des formules et des infos pratiques :
 * services inclus, badge mis en avant, infos pratiques de l'établissement. Les libellés vivent dans lang/{fr,en}/ui.php.
 */
class Presentation
{
    public const INCLUDED = ['tea', 'pastries', 'pool', 'robe', 'towels', 'slippers', 'rest_room', 'terrace', 'products', 'hammam_kit'];

    public const BADGES = ['signature', 'popular'];

    public const GENDER = ['mixed', 'separate', 'women_only', 'men_only', 'private'];

    public const LANGUAGES = ['fr', 'en', 'ar', 'es', 'de', 'it'];

    /** @var list<string> clés d'infos pratiques stockées dans `spas.practical_info` */
    public const PRACTICAL_KEYS = ['gender', 'children', 'pregnant', 'accessible', 'languages', 'bring', 'notes'];

    /** @return array<string, string> */
    public static function includedOptions(): array
    {
        return collect(self::INCLUDED)->mapWithKeys(fn ($k) => [$k => __('ui.included.'.$k)])->all();
    }

    /** @return array<string, string> */
    public static function badgeOptions(): array
    {
        return collect(self::BADGES)->mapWithKeys(fn ($k) => [$k => __('ui.badge.'.$k)])->all();
    }

    /** @return array<string, string> */
    public static function genderOptions(): array
    {
        return collect(self::GENDER)->mapWithKeys(fn ($k) => [$k => __('ui.practical.gender_'.$k)])->all();
    }

    /** @return array<string, string> */
    public static function languageOptions(): array
    {
        return collect(self::LANGUAGES)->mapWithKeys(fn ($k) => [$k => __('ui.practical.lang_'.$k)])->all();
    }

    /** @return array<string, string> options oui / non / sur demande */
    public static function yesNoAskOptions(): array
    {
        return ['yes' => __('ui.practical.yes'), 'no' => __('ui.practical.no'), 'ask' => __('ui.practical.ask')];
    }

    /** Ne garde que les clés connues et les valeurs renseignées. */
    public static function cleanPractical(?array $info): ?array
    {
        if (! $info) {
            return null;
        }
        $out = [];
        foreach (self::PRACTICAL_KEYS as $k) {
            $v = $info[$k] ?? null;
            if (is_array($v)) {
                $v = array_values(array_intersect($v, self::LANGUAGES));
            } elseif (is_string($v)) {
                $v = trim($v);
            }
            if ($v !== null && $v !== '' && $v !== []) {
                $out[$k] = $v;
            }
        }

        return $out ?: null;
    }

    /** @return list<string> clés incluses valides, dans l'ordre de référence */
    public static function cleanIncluded(?array $keys): array
    {
        return array_values(array_intersect(self::INCLUDED, $keys ?? []));
    }

    /**
     * Infos pratiques prêtes à afficher : liste de [label, value] traduits, vide si rien de renseigné.
     *
     * @return list<array{label:string, value:string}>
     */
    public static function practicalRows(?array $info): array
    {
        $info = self::cleanPractical($info) ?? [];
        $rows = [];
        if (isset($info['gender'])) {
            $rows[] = ['label' => __('ui.practical.gender'), 'value' => __('ui.practical.gender_'.$info['gender'])];
        }
        foreach (['children', 'pregnant', 'accessible'] as $k) {
            if (isset($info[$k])) {
                $rows[] = ['label' => __('ui.practical.'.$k), 'value' => __('ui.practical.'.$info[$k])];
            }
        }
        if (! empty($info['languages'])) {
            $rows[] = ['label' => __('ui.practical.languages'), 'value' => collect($info['languages'])->map(fn ($l) => __('ui.practical.lang_'.$l))->implode(', ')];
        }
        foreach (['bring', 'notes'] as $k) {
            if (! empty($info[$k])) {
                $rows[] = ['label' => __('ui.practical.'.$k), 'value' => $info[$k]];
            }
        }

        return $rows;
    }

    /** 105 → « 1h45 », 60 → « 1h », 45 → « 45 min ». */
    public static function duration(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes.' '.__('ui.min');
        }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return $m ? sprintf('%dh%02d', $h, $m) : $h.'h';
    }
}
