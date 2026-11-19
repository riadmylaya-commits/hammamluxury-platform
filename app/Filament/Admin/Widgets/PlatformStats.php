<?php

namespace App\Filament\Admin\Widgets;

use App\Models\Booking;
use App\Models\Partner;
use App\Models\Spa;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PlatformStats extends BaseWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make(__('admin.stat_pending'), Partner::where('status', 'pending')->count().' / '.Spa::where('status', 'pending')->count())->description(__('admin.stat_pending_help'))->color('warning'),
            Stat::make(__('admin.stat_published'), Spa::where('status', 'published')->count()),
            Stat::make(__('admin.stat_waiting'), Booking::where('status', 'waiting')->count())->color('warning'),
        ];
    }
}
