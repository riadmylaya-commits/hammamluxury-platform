<?php

namespace App\Filament\Admin\Resources\SupportRequestResource\Pages;

use App\Filament\Admin\Resources\SupportRequestResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewSupportRequest extends ViewRecord
{
    protected static string $resource = SupportRequestResource::class;

    protected function getHeaderActions(): array
    {
        return SupportRequestResource::supportActions(Action::class);
    }
}
