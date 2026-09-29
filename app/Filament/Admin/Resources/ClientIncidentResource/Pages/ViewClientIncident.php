<?php

namespace App\Filament\Admin\Resources\ClientIncidentResource\Pages;

use App\Filament\Admin\Resources\ClientIncidentResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewClientIncident extends ViewRecord
{
    protected static string $resource = ClientIncidentResource::class;

    protected function getHeaderActions(): array
    {
        return ClientIncidentResource::reviewActions(Action::class);
    }
}
