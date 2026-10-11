<?php

namespace Tests\Engine;

use App\Models\Treatment;

/**
 * Lot 1 « capacité réelle » : rotation entre deux clients, cabine + praticien réservés ensemble,
 * massage à 4 mains, étapes en parallèle, établissement sans horaires = fermé.
 *
 * Établissement : hammam collectif 6 (sans rotation), 3 cabines (rotation 15 min),
 * 2 praticiennes, 1 salle de gommage (rotation 0).
 */
abstract class RealCapacityTestCase extends EngineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->spa('hl-test-spa-reel', 'HL TEST Spa réel');
        $h = $this->type('hammam', 'Hammam');
        $c = $this->type('cabine', 'Cabine de massage', 'unit');
        $c->update(['buffer_min' => 15]);
        $p = $this->type('praticien', 'Praticien(ne)', 'unit');
        $p->update(['kind' => 'therapist']);
        $g = $this->type('gommage', 'Table de gommage', 'unit');
        $this->reload();
        $this->resource($h, 'Hammam', 6);
        foreach ([1, 2, 3] as $i) {
            $this->resource($c, "Cabine $i", 1);
        }
        foreach ([1, 2] as $i) {
            $this->resource($p, "Praticienne $i", 1);
        }
        $this->resource($g, 'Table gommage', 1);

        $this->treatment('hammam-simple', 'Hammam simple', [['type' => 'hammam', 'duration' => 45]]);
        $this->treatment('massage-cabine', 'Massage (cabine seule)', [['type' => 'cabine', 'duration' => 60]]);
        $this->treatment('massage-60', 'Massage 60', [['type' => 'cabine', 'duration' => 60, 'staff' => 1]]);
        $this->treatment('massage-4-mains', 'Massage 4 mains', [['type' => 'cabine', 'duration' => 60, 'staff' => 2]]);
        $this->treatment('hammam-gommage', 'Hammam + gommage pendant', [
            ['type' => 'hammam', 'duration' => 45],
            ['type' => 'gommage', 'duration' => 20, 'parallel' => true, 'staff' => 1],
        ]);
        $this->treatment('rituel', 'Hammam puis massage', [
            ['type' => 'hammam', 'duration' => 40],
            ['type' => 'cabine', 'duration' => 60, 'offset' => 40, 'staff' => 1],
        ]);
    }

    /** Surcharge : accepte les clés `staff` et `parallel` dans les étapes. */
    protected function treatment(string $slug, string $name, array $steps, int $partyMax = 10, array $prices = []): Treatment
    {
        $t = parent::treatment($slug, $name, $steps, $partyMax, $prices);
        foreach ($t->steps->values() as $i => $step) {
            $step->update(['staff_per_person' => $steps[$i]['staff'] ?? 0, 'parallel_with_previous' => $steps[$i]['parallel'] ?? false]);
        }
        $t = $t->fresh('steps');
        $t->update(['duration_min' => $t->computedDuration()]);
        $this->reload();

        return $t->fresh();
    }
}
