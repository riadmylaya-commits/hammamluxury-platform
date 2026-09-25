<?php

namespace App\Domain\Security;

use Filament\Forms\Components\Component;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;

/**
 * Anti-spam des formulaires publics : champ leurre invisible (doit rester vide)
 * + horodatage d'ouverture (une soumission en moins de MIN_SECONDS est un robot).
 */
final class Honeypot
{
    public const FIELD = 'hp_website';

    public const STARTED_FIELD = 'hp_started_at';

    public const MIN_SECONDS = 3;

    /** @return array<int, Component> */
    public static function fields(): array
    {
        return [
            TextInput::make(self::FIELD)->hiddenLabel()->default('')->autocomplete('off')
                ->extraInputAttributes(['tabindex' => '-1'])
                ->extraFieldWrapperAttributes(['class' => 'hidden', 'aria-hidden' => 'true']),
            Hidden::make(self::STARTED_FIELD)->default(fn () => now()->timestamp),
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function isSpam(array $data): bool
    {
        if (filled($data[self::FIELD] ?? null)) {
            return true;
        }
        $started = (int) ($data[self::STARTED_FIELD] ?? 0);

        return $started > 0 && (now()->timestamp - $started) < self::MIN_SECONDS;
    }

    /** @param  array<string, mixed>  $data */
    public static function strip(array $data): array
    {
        unset($data[self::FIELD], $data[self::STARTED_FIELD]);

        return $data;
    }
}
