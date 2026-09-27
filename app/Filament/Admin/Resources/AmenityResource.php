<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\AmenityResource\Pages;
use App\Models\Amenity;

class AmenityResource extends ReferenceResource
{
    protected static ?string $model = Amenity::class;

    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    protected static bool $hasPopular = false;

    public static function getModelLabel(): string
    {
        return __('admin.ref_amenity');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.ref_amenities');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageAmenities::route('/')];
    }
}
