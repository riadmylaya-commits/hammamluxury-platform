<?php

namespace App\Filament\Partner\Resources\ResourceTypeResource\Pages;

use App\Filament\Partner\Resources\ResourceTypeResource;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditResourceType extends EditRecord
{
    protected static string $resource = ResourceTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()->using(function (Model $record, Actions\DeleteAction $action): bool {
            abort_unless((int) $record->spa_id === (int) Filament::getTenant()->id, 403);
            try {
                return $record->delete();
            } catch (ValidationException $e) {
                \Filament\Notifications\Notification::make()->danger()->title(collect($e->errors())->flatten()->first())->send();
                $action->halt();
            }
        })];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless((int) $record->spa_id === (int) Filament::getTenant()->id, 403);
        try {
            $record->update(array_replace($data, ['spa_id' => Filament::getTenant()->id]));

            return $record;
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($messages, $field) => ['data.'.$field => $messages])->all());
        }
    }
}
