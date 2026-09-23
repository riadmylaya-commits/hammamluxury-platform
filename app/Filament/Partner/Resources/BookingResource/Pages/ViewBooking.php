<?php

namespace App\Filament\Partner\Resources\BookingResource\Pages;

use App\Filament\Partner\Resources\BookingResource;
use App\Filament\Shared\BookingActions;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewBooking extends ViewRecord
{
    protected static string $resource = BookingResource::class;

    protected function getHeaderActions(): array
    {
        return BookingActions::all('partner', Action::class);
    }
}
