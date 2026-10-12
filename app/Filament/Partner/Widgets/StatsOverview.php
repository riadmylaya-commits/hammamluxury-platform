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
        $month = $q()->whereBetween('start_at', [now()->startOfMonth(), now()->endOfMonth()]);
        $realized = (clone $month)->whereIn('status', ['completed', 'no_show', 'cancelled'])->where('payment_status', '!=', 'refunded')
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'completed' THEN total WHEN status = 'cancelled' THEN CASE WHEN cancel_fee IS NULL THEN 0 WHEN cancel_fee < total THEN cancel_fee ELSE total END WHEN no_show_fee_waived = 0 AND no_show_fee > 0 THEN CASE WHEN no_show_fee < total THEN no_show_fee ELSE total END ELSE 0 END), 0) AS accrued")->value('accrued');

        return [
            Stat::make(__('partner.stat_waiting'), $q()->where('status', 'waiting')->count())->color('warning')->description(__('partner.stat_waiting_help')),
            Stat::make(__('partner.stat_upcoming'), $q()->where('status', 'confirmed')->where('start_at', '>=', now())->count())->color('success'),
            Stat::make(__('booking.finance_realized_month'), number_format((float) $realized, 0, ',', ' ').' '.config('hl.currency')),
            Stat::make(__('booking.finance_forecast_month'), number_format((float) (clone $month)->where('status', 'confirmed')->where('payment_status', '!=', 'refunded')->sum('total'), 0, ',', ' ').' '.config('hl.currency'))->description(__('booking.finance_not_accrued')),
            Stat::make(__('partner.stat_spa'), __('partner.spa_status_'.$spa->status))->color($spa->status === 'published' ? 'success' : 'gray'),
        ];
    }
}
