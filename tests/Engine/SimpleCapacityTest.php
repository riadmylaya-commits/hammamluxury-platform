<?php

namespace Tests\Engine;

/** Suite « simple » : hammam 6 places / 4 cabines massage (pool) / 2 salles soin visage (pool). */
class SimpleCapacityTest extends EngineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->spa('hl-test-spa-simple', 'HL TEST Spa simple');
        $h = $this->type('hammam', 'Hammam');
        $m = $this->type('massage', 'Cabine de massage');
        $s = $this->type('soin-visage', 'Salle de soin visage');
        $this->resource($h, 'Hammam', 6);
        $this->resource($m, 'Cabines massage', 4);
        $this->resource($s, 'Salles soin visage', 2);
        $this->treatment('hammam-beldi', 'Hammam Beldi', [['type' => 'hammam', 'duration' => 45]]);
        $this->treatment('hammam-premium', 'Hammam Premium', [['type' => 'hammam', 'duration' => 75]]);
        $this->treatment('massage-relaxant', 'Massage relaxant', [['type' => 'massage', 'duration' => 60]]);
        $this->treatment('hammam-massage', 'Hammam + Massage', [['type' => 'massage', 'duration' => 120]]);
        $this->treatment('soin-visage-hydratant', 'Soin visage hydratant', [['type' => 'soin-visage', 'duration' => 50]]);
    }

    public function test_hammam_pool_capacity(): void
    {
        $this->book('H1 groupe 4 Beldi', '10:00', [['hammam-beldi', 4]], true);
        $this->book('H2 couple Premium', '10:15', [['hammam-premium', 2]], true);
        $this->book('H3 7e personne', '10:30', [['hammam-beldi', 1]], false);
        $this->book('H4 après fin H1', '10:45', [['hammam-beldi', 1]], true);
    }

    public function test_massage_pool_capacity(): void
    {
        $this->book('M1 2× relaxant', '10:00', [['massage-relaxant', 2]], true);
        $this->book('M2 2× H+M 120min', '10:00', [['hammam-massage', 2]], true);
        $this->book('M3 5e cabine', '10:30', [['massage-relaxant', 1]], false);
        $this->book('M4 après M1', '11:00', [['massage-relaxant', 1]], true);
        $this->book('M5 3 occupées', '11:30', [['massage-relaxant', 1]], true);
    }

    public function test_facial_capacity_and_basket(): void
    {
        $this->book('S1 2× soin', '10:00', [['soin-visage-hydratant', 2]], true);
        $this->book('S2 3e soin', '10:20', [['soin-visage-hydratant', 1]], false);
        $this->book('S3 après S1', '10:50', [['soin-visage-hydratant', 1]], true);
        $this->book('X1 panier Beldi×2 + Soin×1', '12:00', [['hammam-beldi', 2], ['soin-visage-hydratant', 1]], true);
    }

    public function test_cancellation_releases_capacity(): void
    {
        $this->book('C1 3× soin (cap 2)', '16:00', [['soin-visage-hydratant', 3]], false);
        $c2 = $this->book('C2 2× soin', '16:00', [['soin-visage-hydratant', 2]], true);
        $this->book('C3 plein', '16:10', [['soin-visage-hydratant', 1]], false);
        $this->cancel($c2);
        $this->assertSame([], $this->activeAllocations($c2), 'Annulation : allocations libérées');
        $this->book('C4 après annulation', '16:10', [['soin-visage-hydratant', 1]], true);
    }

    public function test_closed_during_lunch_break(): void
    {
        $this->book('F1 pause 14:00–16:00 fermé', '15:00', [['hammam-beldi', 1]], false);
        $this->book('F2 déborde sur la pause', '13:30', [['hammam-beldi', 1]], false);
        $this->book('F3 se termine à 14:00', '13:15', [['hammam-beldi', 1]], true);
    }

    public function test_free_capacity_snapshot(): void
    {
        $this->book('H1 groupe 4 Beldi', '10:00', [['hammam-beldi', 4]], true);
        $this->book('H2 couple Premium', '10:15', [['hammam-premium', 2]], true);
        $this->book('M1 2× relaxant', '10:00', [['massage-relaxant', 2]], true);
        $this->book('M2 2× H+M 120min', '10:00', [['hammam-massage', 2]], true);
        $this->book('S1 2× soin', '10:00', [['soin-visage-hydratant', 2]], true);
        $free = $this->engine->freeCapacity($this->spa, $this->at('10:30'), $this->at('10:31'));
        $this->assertSame(['hammam' => 0, 'massage' => 0, 'soin-visage' => 0], $free, 'Places libres à 10:30 = 0/0/0');
        $free = $this->engine->freeCapacity($this->spa, $this->at('12:30'), $this->at('12:31'));
        $this->assertSame(['hammam' => 6, 'massage' => 4, 'soin-visage' => 2], $free, 'Places libres à 12:30 = tout libre');
    }
}
