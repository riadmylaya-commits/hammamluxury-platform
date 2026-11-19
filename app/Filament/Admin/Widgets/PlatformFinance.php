<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Shared\FinanceStats;
use App\Models\Booking;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;

class PlatformFinance extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getHeading(): ?string
    {
        return __('booking.finance_heading');
    }

    protected function getDescription(): ?string
    {
        return __('booking.finance_intermediary');
    }

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        return FinanceStats::stats(Booking::query());
    }
}
