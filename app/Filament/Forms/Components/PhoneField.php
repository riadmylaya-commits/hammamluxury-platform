<?php

namespace App\Filament\Forms\Components;

use App\Domain\Phone\PhoneNumber;
use App\Domain\Phone\PhoneRule;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;

/**
 * Sélecteur de pays (drapeau + indicatif) + numéro national ; l'état du champ `$name` est un E.164 (+212661351989).
 * Le pays est un champ auxiliaire `{name}_country`, jamais enregistré.
 */
final class PhoneField
{
    public static function make(string $name, string $label, bool $required = false): Group
    {
        $countryField = $name.'_country';

        return Group::make([
            Select::make($countryField)
                ->label(__('phone.country'))
                ->options(fn () => PhoneNumber::options())
                ->default(fn () => PhoneNumber::detectCountry())
                ->selectablePlaceholder(false)
                ->native(false)
                ->searchable()
                ->live()
                ->dehydrated(false)
                ->columnSpan(1),
            TextInput::make($name)
                ->label($label)
                ->tel()
                ->required($required)
                ->maxLength(25)
                ->placeholder('0661351989')
                ->helperText(__('phone.help'))
                ->prefix(fn (Get $get) => '+'.PhoneNumber::dialCode((string) ($get($countryField) ?: PhoneNumber::DEFAULT_COUNTRY)))
                ->afterStateHydrated(function (TextInput $component, ?string $state, Get $get, callable $set) use ($countryField): void {
                    if (! is_string($state) || $state === '') {
                        return;
                    }
                    $iso = PhoneNumber::countryOf($state);
                    if ($iso !== null) {
                        $set($countryField, $iso);
                        $component->state(PhoneNumber::nationalPart($state));
                    }
                })
                ->rules(fn (Get $get): array => [new PhoneRule((string) ($get($countryField) ?: PhoneNumber::DEFAULT_COUNTRY))])
                ->dehydrateStateUsing(fn (?string $state, Get $get) => PhoneNumber::normalize($state, (string) ($get($countryField) ?: PhoneNumber::DEFAULT_COUNTRY)))
                ->columnSpan(2),
        ])->columns(3);
    }
}
