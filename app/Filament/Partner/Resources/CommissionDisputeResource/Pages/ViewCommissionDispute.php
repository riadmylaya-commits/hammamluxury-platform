<?php

namespace App\Filament\Partner\Resources\CommissionDisputeResource\Pages;

use App\Filament\Partner\Resources\CommissionDisputeResource;
use Filament\Resources\Pages\ViewRecord;

class ViewCommissionDispute extends ViewRecord
{
    protected static string $resource = CommissionDisputeResource::class;

    protected function getHeaderActions(): array
    {
        return CommissionDisputeResource::viewActions();
    }
}
