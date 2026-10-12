<?php

namespace App\Filament\Admin\Widgets;

use App\Models\Booking;
use App\Models\LedgerEntry;
use App\Models\Partner;
use App\Models\Spa;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PlatformStats extends BaseWidget
{
    protected function getStats(): array
    {
        $range = [now()->startOfMonth(), now()->endOfMonth()];
        $month = Booking::whereBetween('start_at', $range);
        $realized = (clone $month)->whereIn('status', ['completed', 'no_show'])->where('payment_status', '!=', 'refunded')
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'completed' THEN total WHEN no_show_fee_waived = 0 AND no_show_fee > 0 THEN CASE WHEN no_show_fee < total THEN no_show_fee ELSE total END ELSE 0 END), 0) AS accrued")->value('accrued');
        $commission = LedgerEntry::whereIn('type', LedgerEntry::COMMISSION_TYPES)
            ->whereIn('booking_id', (clone $month)->select('id'))->sum('amount');
        $fmt = fn ($v) => number_format((float) $v, 0, ',', ' ').' '.config('hl.currency');

        return [
            Stat::make(__('admin.stat_pending'), Partner::where('status', 'pending')->count().' / '.Spa::where('status', 'pending')->count())->description(__('admin.stat_pending_help'))->color('warning'),
            Stat::make(__('admin.stat_published'), Spa::where('status', 'published')->count()),
            Stat::make(__('admin.stat_waiting'), Booking::where('status', 'waiting')->count())->color('warning'),
            Stat::make(__('booking.finance_realized_month'), $fmt($realized))->description(__('booking.finance_ledger_commission').' '.$fmt($commission))->color('success'),
            Stat::make(__('booking.finance_forecast_month'), $fmt((clone $month)->where('status', 'confirmed')->where('payment_status', '!=', 'refunded')->sum('total')))->description(__('booking.finance_not_accrued')),
        ];
    }
}
