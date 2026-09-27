<?php

namespace App\Filament\Partner\Resources\TreatmentResource\Pages;

use App\Filament\Partner\Resources\TreatmentResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTreatment extends EditRecord
{
    protected static string $resource = TreatmentResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }

    protected function afterSave(): void
    {
        TreatmentResource::syncDuration($this->record);
        $this->record->spa->refreshPriceFrom();
    }
}
