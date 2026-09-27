<?php

namespace App\Filament\Partner\Resources\ResourceTypeResource\Pages;

use App\Filament\Partner\Resources\ResourceTypeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListResourceTypes extends ListRecords
{
    protected static string $resource = ResourceTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label(__('partner.add_resource_type'))];
    }
}
