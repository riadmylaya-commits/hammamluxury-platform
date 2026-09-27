<?php

namespace App\Filament\Partner\Resources\BlockResource\Pages;

use App\Filament\Partner\Resources\BlockResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBlocks extends ListRecords
{
    protected static string $resource = BlockResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label(__('partner.add_block'))];
    }
}
