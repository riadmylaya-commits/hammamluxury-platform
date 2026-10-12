<?php

namespace App\Filament\Partner\Resources\BlockResource\Pages;

use App\Filament\Partner\Resources\BlockResource;
use App\Models\Block;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateBlock extends CreateRecord
{
    protected static string $resource = BlockResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return Block::create(array_replace($data, ['spa_id' => Filament::getTenant()->id, 'created_by' => Filament::auth()->id()]));
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($messages, $field) => ['data.'.$field => $messages])->all());
        }
    }
}
