<?php

namespace App\Filament\Partner\Widgets;

use App\Filament\Partner\Pages\EditSpaProfile;
use App\Filament\Partner\Resources\BookingResource;
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
        $clickable = ['class' => 'cursor-pointer transition hover:ring-2 hover:ring-primary-500'];
        $bookings = fn (array $filters) => BookingResource::getUrl('index', ['tableFilters' => $filters]);

        return [
            Stat::make(__('partner.stat_waiting'), $q()->where('status', 'waiting')->count())->color('warning')->description(__('partner.stat_waiting_help', ['delay' => $spa->waitingTtlLabel()]))
                ->url($bookings(['status' => ['values' => ['waiting']]]))->extraAttributes($clickable),
            Stat::make(__('partner.stat_upcoming'), $q()->where('status', 'confirmed')->where('start_at', '>=', now())->count())->color('success')
                ->url($bookings(['status' => ['values' => ['confirmed']], 'upcoming' => ['isActive' => true]]))->extraAttributes($clickable),
            Stat::make(__('partner.stat_spa'), __('partner.spa_status_'.$spa->status))->color($spa->status === 'published' ? 'success' : 'gray')
                ->url(EditSpaProfile::getUrl())->extraAttributes($clickable),
        ];
    }
}
