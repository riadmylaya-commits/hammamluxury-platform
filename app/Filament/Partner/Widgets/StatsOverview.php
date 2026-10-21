<?php

namespace App\Filament\Partner\Widgets;

use App\Filament\Partner\Pages\CommissionStatement;
use App\Filament\Partner\Pages\EditSpaProfile;
use App\Filament\Partner\Resources\BookingResource;
use App\Filament\Shared\BillingPeriods;
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
        $accrued = (clone $month)->whereIn('status', ['completed', 'no_show', 'cancelled'])->where('payment_status', '!=', 'refunded')->get();
        $realized = $accrued->sum(fn (Booking $b) => $b->amountDue());
        $realizedNet = $accrued->sum(fn (Booking $b) => $b->netDue());
        $forecast = (clone $month)->where('status', 'confirmed')->where('payment_status', '!=', 'refunded')->get();
        $money = fn (float $v) => number_format($v, 0, ',', ' ').' '.config('hl.currency');
        $clickable = ['class' => 'cursor-pointer transition hover:ring-2 hover:ring-primary-500'];
        $bookings = fn (array $filters) => BookingResource::getUrl('index', ['tableFilters' => $filters]);

        return [
            Stat::make(__('partner.stat_waiting'), $q()->where('status', 'waiting')->count())->color('warning')->description(__('partner.stat_waiting_help'))
                ->url($bookings(['status' => ['values' => ['waiting']]]))->extraAttributes($clickable),
            Stat::make(__('partner.stat_upcoming'), $q()->where('status', 'confirmed')->where('start_at', '>=', now())->count())->color('success')
                ->url($bookings(['status' => ['values' => ['confirmed']], 'upcoming' => ['isActive' => true]]))->extraAttributes($clickable),
            Stat::make(__('booking.finance_realized_month'), $money((float) $realized))
                ->description(__('booking.finance_realized_net', ['amount' => $money((float) $realizedNet)]))
                ->url(CommissionStatement::getUrl(['period' => BillingPeriods::current()]))->extraAttributes($clickable),
            Stat::make(__('booking.finance_forecast_month'), $money((float) $forecast->sum('total')))
                ->description(__('booking.finance_forecast_net', ['amount' => $money((float) $forecast->sum(fn (Booking $b) => $b->netForPartner()))]))
                ->url($bookings(['status' => ['values' => ['confirmed']]]))->extraAttributes($clickable),
            Stat::make(__('partner.stat_spa'), __('partner.spa_status_'.$spa->status))->color($spa->status === 'published' ? 'success' : 'gray')
                ->url(EditSpaProfile::getUrl())->extraAttributes($clickable),
        ];
    }
}
