<?php

namespace App\Domain\Billing;

use App\Models\Partner;
use App\Models\Spa;
use Carbon\CarbonImmutable;

/**
 * Relevé provisoire d'une entité juridique partenaire pour un mois : lignes par réservation, sous-totaux par établissement.
 * Lecture seule ; aucune facture, aucun numéro, aucune écriture.
 */
final class Statement
{
    /**
     * @param  array<int, array{spa: Spa, lines: list<array<string, mixed>>, total: float}>  $spas  indexé par spa_id
     * @param  list<array<string, mixed>>  $excluded  lignes retirées du relevé facturable (contestation ouverte, arbitrage)
     * @param  list<string>  $blockers
     */
    public function __construct(
        public readonly Partner $partner,
        public readonly CarbonImmutable $period,
        public readonly CarbonImmutable $acquiredUntil,
        public readonly array $spas,
        public readonly array $excluded,
        public readonly float $total,
        public readonly float $excludedTotal,
        public readonly bool $closed,
        public readonly ?CarbonImmutable $disputeUntil,
        public readonly array $blockers,
    ) {}

    public function periodKey(): string
    {
        return $this->period->format('Y-m');
    }

    public function isEmpty(): bool
    {
        return $this->spas === [] && $this->excluded === [];
    }

    /** Lignes facturables de toutes les sections. @return list<array<string, mixed>> */
    public function lines(): array
    {
        $out = [];
        foreach ($this->spas as $section) {
            foreach ($section['lines'] as $line) {
                $out[] = $line;
            }
        }

        return $out;
    }

    /** Les contestations restent possibles jusqu'au `dispute_until_day` du mois suivant inclus. */
    public function disputeWindowOpen(?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now(config('hl.timezone'));

        return $this->disputeUntil !== null && $now->lte($this->disputeUntil);
    }
}
