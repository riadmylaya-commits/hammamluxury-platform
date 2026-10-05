<?php

namespace App\Filament\Partner\Resources\BookingResource\Pages;

use App\Filament\Partner\Resources\BookingResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListBookings extends ListRecords
{
    protected static string $resource = BookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('calendar')->label(__('partner.calendar_view'))->icon('heroicon-o-calendar-days')->color('gray')
                ->url(BookingResource::getUrl('calendar')),
        ];
    }
}
