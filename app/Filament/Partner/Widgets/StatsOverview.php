<?php

namespace App\Filament\Partner\Widgets;

use App\Models\Booking;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $spa = Filament::getTenant();
        $q = fn () => Booking::where('spa_id', $spa->id);
        $month = $q()->whereIn('status', ['confirmed', 'completed'])->whereBetween('start_at', [now()->startOfMonth(), now()->endOfMonth()]);

        return [
            Stat::make(__('partner.stat_waiting'), $q()->where('status', 'waiting')->count())->color('warning')->description(__('partner.stat_waiting_help')),
            Stat::make(__('partner.stat_upcoming'), $q()->where('status', 'confirmed')->where('start_at', '>=', now())->count())->color('success'),
            Stat::make(__('partner.stat_month'), number_format((float) $month->sum('total'), 0, ',', ' ').' '.config('hl.currency'))->description($month->count().' '.__('partner.bookings_lc')),
            Stat::make(__('partner.stat_spa'), __('partner.spa_status_'.$spa->status))->color($spa->status === 'published' ? 'success' : 'gray'),
        ];
    }
}
