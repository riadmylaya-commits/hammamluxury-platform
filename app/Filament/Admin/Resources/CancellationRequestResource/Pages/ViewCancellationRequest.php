<?php

namespace App\Filament\Admin\Resources\CancellationRequestResource\Pages;

use App\Filament\Admin\Resources\CancellationRequestResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewCancellationRequest extends ViewRecord
{
    protected static string $resource = CancellationRequestResource::class;

    protected function getHeaderActions(): array
    {
        return CancellationRequestResource::decisionActions(Action::class);
    }
}
