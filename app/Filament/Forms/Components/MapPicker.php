<?php

namespace App\Filament\Forms\Components;

use App\Domain\Geo\Geocoder;
use App\Models\City;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;

/**
 * Carte OpenStreetMap avec repère déplaçable ; l'état est `['lat' => float|null, 'lng' => float|null]`.
 * Le bouton « Localiser » géocode l'adresse (`address` + ville `city_id`) et place le repère, que le partenaire ajuste ensuite.
 */
class MapPicker extends Field
{
    protected string $view = 'filament.forms.map-picker';

    /** Centre par défaut : Maroc. */
    public const DEFAULT_LAT = 31.7917;

    public const DEFAULT_LNG = -7.0926;

    protected function setUp(): void
    {
        parent::setUp();

        $this->default(['lat' => null, 'lng' => null])
            ->afterStateHydrated(fn (MapPicker $component, $state) => $component->state([
                'lat' => isset($state['lat']) && $state['lat'] !== '' ? (float) $state['lat'] : null,
                'lng' => isset($state['lng']) && $state['lng'] !== '' ? (float) $state['lng'] : null,
            ]))
            ->rules(['array'])
            ->hintAction(
                Action::make('locate')
                    ->label(__('partner.map_locate'))
                    ->icon('heroicon-m-magnifying-glass')
                    ->action(function (Get $get, Set $set, MapPicker $component) {
                        $address = (string) $get('address');
                        if (trim($address) === '') {
                            Notification::make()->title(__('partner.map_need_address'))->warning()->send();

                            return;
                        }
                        $city = City::find($get('city_id'));
                        $hit = app(Geocoder::class)->search($address, $city?->name_fr, strtolower($city?->country ?? 'ma'));
                        if (! $hit) {
                            Notification::make()->title(__('partner.map_not_found'))->warning()->send();

                            return;
                        }
                        $set($component->getName(), ['lat' => $hit['lat'], 'lng' => $hit['lng']]);
                        Notification::make()->title(__('partner.map_found'))->body($hit['label'])->success()->send();
                    })
            );
    }

    public function hasCoordinates(): bool
    {
        $s = $this->getState();

        return isset($s['lat'], $s['lng']);
    }
}
