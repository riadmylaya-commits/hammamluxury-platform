<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Shared\FinanceStats;
use App\Filament\Shared\HasFinanceFilters;
use App\Models\Booking;
use App\Models\Spa;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;

class PlatformFinance extends BaseWidget
{
    use HasFinanceFilters;

    protected static ?int $sort = 1;

    protected static string $view = 'filament.shared.finance-stats';

    public ?string $spaId = null;

    /** @return array<int, string> */
    public function spaOptions(): array
    {
        return Spa::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    protected function getStats(): array
    {
        $spa = filled($this->spaId) ? Spa::find((int) $this->spaId) : null;

        // « Facturée » et « payée » sont tenues par partenaire (entité facturée), pas par établissement.
        return FinanceStats::stats($spa ? Booking::where('spa_id', $spa->id) : Booking::query(), $spa?->partner_id, [], $this->period);
    }
}
