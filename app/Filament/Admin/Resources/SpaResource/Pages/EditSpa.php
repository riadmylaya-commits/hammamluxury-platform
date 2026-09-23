<?php

namespace App\Filament\Admin\Resources\SpaResource\Pages;

use App\Domain\Catalogue\PublicationChecklist;
use App\Filament\Admin\Resources\SpaResource;
use App\Models\Spa;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditSpa extends EditRecord
{
    protected static string $resource = SpaResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Spa $spa */
        $spa = $this->record;
        if (($data['status'] ?? null) === 'published' && $spa->status !== 'published' && ! PublicationChecklist::passes($spa)) {
            Notification::make()->title(__('admin.checklist_blocking'))->body(implode(' · ', PublicationChecklist::failures($spa)))->danger()->send();
            $this->halt();
        }
        if (($data['status'] ?? null) === 'published') {
            $data['published_at'] = $spa->published_at ?? now();
        }

        return $data;
    }
}
