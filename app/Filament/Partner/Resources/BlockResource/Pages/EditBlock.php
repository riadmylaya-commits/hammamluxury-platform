<?php

namespace App\Filament\Partner\Resources\BlockResource\Pages;

use App\Filament\Partner\Resources\BlockResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditBlock extends EditRecord
{
    protected static string $resource = BlockResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless((int) $record->spa_id === (int) Filament::getTenant()->id, 403);
        unset($data['created_by']);
        try {
            $record->update(array_replace($data, ['spa_id' => Filament::getTenant()->id]));

            return $record;
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($messages, $field) => ['data.'.$field => $messages])->all());
        }
    }
}
