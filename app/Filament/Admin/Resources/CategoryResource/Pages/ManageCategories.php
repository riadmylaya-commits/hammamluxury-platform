<?php

namespace App\Filament\Admin\Resources\CategoryResource\Pages;

use App\Filament\Admin\Resources\CategoryResource;
use App\Filament\Admin\Resources\ReferenceResource\Pages\ManageReference;

class ManageCategories extends ManageReference
{
    protected static string $resource = CategoryResource::class;
}
