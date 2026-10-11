<?php

namespace App\Filament\Partner\Widgets;

use App\Domain\Catalogue\CapacityReadiness;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/** Indicateur « configuration complète » et état de la réservation instantanée. */
class ConfigurationStatus extends Widget
{
    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    protected static string $view = 'filament.partner.widgets.configuration-status';

    protected function getViewData(): array
    {
        $spa = Filament::getTenant();

        return ['spa' => $spa, 'checks' => CapacityReadiness::checks($spa), 'complete' => CapacityReadiness::passes($spa)];
    }
}
