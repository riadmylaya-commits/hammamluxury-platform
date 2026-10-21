<?php

namespace App\Filament\Shared;

use Carbon\CarbonImmutable;

/** Liste des mois proposés dans les sélecteurs de relevé (mois courant + 12 précédents). */
class BillingPeriods
{
    /** @return array<string, string> */
    public static function options(int $months = 12): array
    {
        $out = [];
        $m = CarbonImmutable::now(config('hl.timezone'))->startOfMonth();
        for ($i = 0; $i <= $months; $i++) {
            $p = $m->subMonthsNoOverflow($i);
            $out[$p->format('Y-m')] = ucfirst($p->translatedFormat('F Y'));
        }

        return $out;
    }

    public static function current(): string
    {
        return CarbonImmutable::now(config('hl.timezone'))->format('Y-m');
    }

    public static function previous(): string
    {
        return CarbonImmutable::now(config('hl.timezone'))->subMonthNoOverflow()->format('Y-m');
    }

    public static function valid(?string $period): bool
    {
        return is_string($period) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) === 1;
    }
}
