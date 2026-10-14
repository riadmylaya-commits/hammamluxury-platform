<?php

namespace App\Filament\Admin\Resources\CommissionDisputeResource\Pages;

use App\Filament\Admin\Resources\CommissionDisputeResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewCommissionDispute extends ViewRecord
{
    protected static string $resource = CommissionDisputeResource::class;

    protected function getHeaderActions(): array
    {
        return CommissionDisputeResource::decisionActions(Action::class);
    }
}
