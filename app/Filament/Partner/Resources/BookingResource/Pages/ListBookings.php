<?php

namespace App\Filament\Partner\Resources\BookingResource\Pages;

use App\Filament\Partner\Resources\BookingResource;
use Filament\Resources\Pages\ListRecords;

class ListBookings extends ListRecords
{
    protected static string $resource = BookingResource::class;
}
