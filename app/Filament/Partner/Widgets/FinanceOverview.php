<?php

namespace App\Filament\Partner\Widgets;

use App\Filament\Partner\Pages\CommissionStatement;
use App\Filament\Partner\Resources\BookingResource;
use App\Filament\Shared\BillingPeriods;
use App\Filament\Shared\FinanceStats;
use App\Models\Booking;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;

class FinanceOverview extends BaseWidget
{
    protected static ?int $sort = 3;

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
        $spa = Filament::getTenant();

        return FinanceStats::stats(Booking::where('spa_id', $spa->id), $spa->partner_id, [
            'confirmed' => BookingResource::getUrl('index', ['tableFilters' => ['status' => ['values' => ['confirmed']]]]),
            'accrued' => CommissionStatement::getUrl(['period' => BillingPeriods::current()]),
        ]);
    }
}
