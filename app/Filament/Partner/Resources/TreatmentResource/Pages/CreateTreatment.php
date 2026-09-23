<?php

namespace App\Filament\Partner\Resources\TreatmentResource\Pages;

use App\Filament\Partner\Resources\TreatmentResource;
use App\Models\Treatment;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateTreatment extends CreateRecord
{
    protected static string $resource = TreatmentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $base = Str::slug($data['name_fr']) ?: 'soin';
        $slug = $base;
        for ($i = 2; Treatment::where('spa_id', Filament::getTenant()->id)->where('slug', $slug)->exists(); $i++) {
            $slug = "$base-$i";
        }
        $data['slug'] = $slug;
        $data['duration_min'] = 0;

        return $data;
    }

    protected function afterCreate(): void
    {
        TreatmentResource::syncDuration($this->record);
        $this->record->spa->refreshPriceFrom();
    }
}
