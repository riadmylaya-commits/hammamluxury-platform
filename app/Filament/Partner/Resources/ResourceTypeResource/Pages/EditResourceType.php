<?php

namespace App\Filament\Partner\Resources\ResourceTypeResource\Pages;

use App\Filament\Partner\Resources\ResourceTypeResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditResourceType extends EditRecord
{
    protected static string $resource = ResourceTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
