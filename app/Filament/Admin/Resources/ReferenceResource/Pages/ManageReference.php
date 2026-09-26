<?php

namespace App\Filament\Admin\Resources\ReferenceResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

/** Page unique liste + modales, partagée par les trois référentiels via `getResource()`. */
abstract class ManageReference extends ManageRecords
{
    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
