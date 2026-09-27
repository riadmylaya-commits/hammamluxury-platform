<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\CategoryResource\Pages;
use App\Models\Category;

class CategoryResource extends ReferenceResource
{
    protected static ?string $model = Category::class;

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static bool $hasPopular = true;

    public static function getModelLabel(): string
    {
        return __('admin.ref_category');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.ref_categories');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageCategories::route('/')];
    }
}
