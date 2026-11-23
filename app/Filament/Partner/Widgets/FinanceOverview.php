<?php

namespace App\Filament\Partner\Widgets;

use App\Filament\Partner\Pages\CommissionStatement;
use App\Filament\Partner\Resources\BookingResource;
use App\Filament\Shared\FinanceStats;
use App\Filament\Shared\HasFinanceFilters;
use App\Models\Booking;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;

class FinanceOverview extends BaseWidget
{
    use HasFinanceFilters;

    protected static ?int $sort = 3;

    protected static string $view = 'filament.shared.finance-stats';

    protected function getStats(): array
    {
        $spa = Filament::getTenant();

        return FinanceStats::stats(Booking::where('spa_id', $spa->id), $spa->partner_id, [
            'confirmed' => BookingResource::getUrl('index', ['tableFilters' => ['status' => ['values' => ['confirmed']]]]),
            'accrued' => CommissionStatement::getUrl(['period' => $this->period]),
        ], $this->period);
    }
}
