<?php

namespace App\Filament\Shared;

/** Filtre « mois » du suivi financier (Admin et partenaire) : ne change que la période lue, jamais les calculs. */
trait HasFinanceFilters
{
    public string $period = '';

    public function mountHasFinanceFilters(): void
    {
        $this->period = BillingPeriods::current();
    }

    public function updatedPeriod(): void
    {
        if (! array_key_exists($this->period, BillingPeriods::options())) {
            $this->period = BillingPeriods::current();
        }
    }

    /** @return array<string, string> */
    public function periodOptions(): array
    {
        return BillingPeriods::options();
    }

    /** @return array<int|string, string> établissements proposés (vide : pas de filtre établissement) */
    public function spaOptions(): array
    {
        return [];
    }

    protected function getHeading(): ?string
    {
        return __('booking.finance_heading_period', ['period' => $this->periodOptions()[$this->period] ?? '']);
    }

    protected function getDescription(): ?string
    {
        return __('booking.finance_intermediary');
    }

    protected function getColumns(): int
    {
        return 3;
    }
}
