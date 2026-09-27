<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\CityResource\Pages;
use App\Models\City;

class CityResource extends ReferenceResource
{
    protected static ?string $model = City::class;

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';

    protected static bool $hasPopular = true;

    public static function getModelLabel(): string
    {
        return __('admin.ref_city');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.ref_cities');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageCities::route('/')];
    }
}
