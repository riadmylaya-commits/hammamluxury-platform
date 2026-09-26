<?php

namespace Tests\Engine;

use App\Models\Extra;
use App\Models\Resource;
use App\Models\ResourceType;
use Carbon\CarbonImmutable;

/** Suite « complexe » : 2 hammams privés (unit) + collectif 8 + 5 cabines + 2 cabines couple + 2 salles soin. */
class ComplexCapacityTest extends EngineTestCase
{
    private ResourceType $ci;

    private Resource $couple1;

    private Extra $ex;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spa('hl-test-spa-complex', 'HL TEST Spa complexe');
        $hp = $this->type('hammam-prive', 'Hammam privé', 'unit');
        $hc = $this->type('hammam', 'Hammam collectif', 'pool');
        $this->ci = $this->type('massage', 'Cabine individuelle', 'unit');
        $cc = $this->type('cabine-couple', 'Cabine couple', 'unit');
        $sv = $this->type('soin-visage', 'Salle de soin visage', 'unit');
        $this->resource($hp, 'Hammam privé A', 6);
        $this->resource($hp, 'Hammam privé B', 6);
        $this->resource($hc, 'Hammam collectif', 8);
        for ($i = 1; $i <= 5; $i++) {
            $this->resource($this->ci, "Cabine $i", 1);
        }
        $this->couple1 = $this->resource($cc, 'Cabine couple 1', 2, 2, 2);
        $this->resource($cc, 'Cabine couple 2', 2, 2, 2);
        $this->resource($sv, 'Salle soin 1', 1);
        $this->resource($sv, 'Salle soin 2', 1);

        $this->treatment('hammam-collectif', 'Hammam collectif', [['type' => 'hammam', 'duration' => 60]]);
        $this->treatment('hammam-prive', 'Hammam privé', [['type' => 'hammam-prive', 'duration' => 60]], 6);
        $this->treatment('massage-relaxant', 'Massage relaxant', [['type' => 'massage', 'duration' => 60]]);
        $this->treatment('massage-couple', 'Massage couple', [['type' => 'cabine-couple', 'duration' => 60]], 2);
        $this->treatment('soin-visage', 'Soin visage', [['type' => 'soin-visage', 'duration' => 50]]);
        $pkg = $this->treatment('hammam-massage', 'Hammam + Massage', [['type' => 'hammam', 'duration' => 45], ['type' => 'massage', 'duration' => 75, 'offset' => 45]]);
        $this->ex = $this->extra($pkg, 'Massage crânien', 125, 15);
    }

    public function test_collective_hammam_pool_of_8(): void
    {
        $this->book('HC1 groupe 5', '10:00', [['hammam-collectif', 5]], true);
        $this->book('HC2 groupe 3 (=8)', '10:30', [['hammam-collectif', 3]], true);
        $this->book('HC3 9e personne', '10:45', [['hammam-collectif', 1]], false);
        $this->book('HC4 après HC1', '11:00', [['hammam-collectif', 4]], true);
    }

    public function test_private_hammams_are_exclusive(): void
    {
        $this->book('HP1 privé groupe 4', '10:00', [['hammam-prive', 4]], true);
        $this->book('HP2 privé couple', '10:00', [['hammam-prive', 2]], true);
        $this->book('HP3 3e privé (2 seulement)', '10:30', [['hammam-prive', 1]], false);
        $this->book('HP4 privé 7 pers (>6)', '12:00', [['hammam-prive', 7]], false);
    }

    public function test_individual_cabins_one_per_person(): void
    {
        $b = $this->book('CI1 massage 3 pers', '10:00', [['massage-relaxant', 3]], true);
        $this->assertCount(3, $this->activeAllocations($b), 'CI1 : 3 cabines distinctes '.$this->fmt($this->activeAllocations($b)));
        $this->book('CI2 massage 2 pers (=5)', '10:15', [['massage-relaxant', 2]], true);
        $this->book('CI3 6e cabine', '10:30', [['massage-relaxant', 1]], false);
    }

    public function test_couple_cabins_min_two_exclusive(): void
    {
        $this->book('CC1 couple', '10:00', [['massage-couple', 2]], true);
        $this->book('CC2 couple', '10:00', [['massage-couple', 2]], true);
        $this->book('CC3 3e couple', '10:30', [['massage-couple', 2]], false);
        $this->book('CC4 solo en cabine couple', '12:00', [['massage-couple', 1]], false);
    }

    public function test_facial_rooms_independent(): void
    {
        $this->book('SV1 2 soins', '10:00', [['soin-visage', 2]], true);
        $this->book('SV2 3e soin', '10:20', [['soin-visage', 1]], false);
    }

    public function test_sequential_package_and_extra_extends_last_step(): void
    {
        $this->book('HC4 hammam 4/8', '11:00', [['hammam-collectif', 4]], true);
        $p1 = $this->book('PK1 Hammam+Massage 2 pers', '11:30', [['hammam-massage', 2]], true);
        $a = $this->activeAllocations($p1);
        $hammam = array_filter($a, fn ($x) => $x->start_at->format('H:i') === '11:30' && $x->end_at->format('H:i') === '12:15');
        $cabines = array_filter($a, fn ($x) => $x->start_at->format('H:i') === '12:15' && $x->end_at->format('H:i') === '13:30');
        $this->assertTrue(count($a) === 3 && count($hammam) === 1 && count($cabines) === 2, 'PK1 séquence : hammam 11:30–12:15 puis 2 cabines exclusives 12:15–13:30 '.$this->fmt($a));

        $p2 = $this->book('PK2 H+M + extra crânien', '11:30', [['hammam-massage', 1, [$this->ex->id]]], true);
        $a2 = $this->activeAllocations($p2);
        $this->assertSame('13:45', end($a2)->end_at->format('H:i'), 'PK2 extra +15 min : massage se termine 13:45 '.$this->fmt($a2));
        $this->assertSame('12:15', $a2[0]->end_at->format('H:i'), 'PK2 : le hammam n’est pas prolongé par l’extra');
        $this->assertSame(135, $p2->duration_min, 'PK2 : durée totale 120 + 15 = 135 min');

        $this->book('SV3 soins pendant packages', '12:30', [['soin-visage', 2]], true);
    }

    public function test_type_block_maintenance(): void
    {
        $this->block('type', "{$this->day} 17:00:00", "{$this->day} 18:00:00", 'maintenance', $this->ci);
        $this->book('BT1 massage pendant maintenance cabines', '17:15', [['massage-relaxant', 1]], false);
        $this->book('BT2 hammam collectif pendant maintenance cabines', '17:15', [['hammam-collectif', 2]], true);
        $this->book('BT3 massage après maintenance', '18:00', [['massage-relaxant', 1]], true);
        $this->book('BT4 package déborde sur la maintenance', '16:00', [['hammam-massage', 1]], false);
    }

    public function test_resource_block_private(): void
    {
        $this->block('resource', "{$this->day} 19:00:00", "{$this->day} 21:00:00", 'private', null, $this->couple1);
        $this->book('BR1 couple (cabine 2)', '19:00', [['massage-couple', 2]], true);
        $this->book('BR2 2e couple (cabine 1 bloquée)', '19:00', [['massage-couple', 2]], false);
        $this->book('BR3 couple après blocage', '21:00', [['massage-couple', 2]], true);
    }

    public function test_spa_block_holiday_and_closed_day(): void
    {
        $next = CarbonImmutable::parse($this->day)->addDay();
        $this->block('spa', $next->format('Y-m-d 00:00:00'), $next->addDays(2)->format('Y-m-d 00:00:00'), 'holiday');
        $this->book('BL1 réservation pendant vacances', '10:00', [['hammam-collectif', 1]], false, 'confirmed', $next->format('Y-m-d'));
        $this->book('BL2 réservation après vacances', '10:00', [['hammam-collectif', 1]], true, 'confirmed', $next->addDays(2)->format('Y-m-d'));
        $sunday = CarbonImmutable::parse($this->day)->next(CarbonImmutable::SUNDAY)->format('Y-m-d');
        $this->book('CL1 dimanche fermé', '10:00', [['hammam-collectif', 1]], false, 'confirmed', $sunday);
    }

    public function test_lock_is_reentrant_on_same_connection(): void
    {
        $this->assertTrue($this->engine->lock($this->spa) && $this->engine->lock($this->spa), 'Verrou GET_LOCK acquis (réentrant sur la même connexion)');
        $this->engine->unlock($this->spa);
        $this->engine->unlock($this->spa);
        $this->assertSame(42, $this->engine->withLock($this->spa, fn () => 42), 'withLock exécute et libère');
    }

    public function test_available_starts_for_couple(): void
    {
        $this->book('CC1 couple', '10:00', [['massage-couple', 2]], true);
        $this->book('CC2 couple', '10:00', [['massage-couple', 2]], true);
        $starts = $this->availability(['treatment' => 'massage-couple', 'party' => 2])['times'];
        $this->assertTrue(in_array('09:00', $starts, true) && ! in_array('10:00', $starts, true) && ! in_array('09:30', $starts, true) && in_array('11:00', $starts, true), 'Créneaux couple : 09:00 dispo, 09:30/10:00 complets, 11:00 dispo — '.implode(' ', $starts));
        $this->assertFalse(in_array('13:30', $starts, true), 'Créneaux couple : 13:30 refusé (pause à 14:00)');
        $this->assertTrue(in_array('22:00', $starts, true) && ! in_array('22:30', $starts, true), 'Créneaux couple : 22:00 dernier départ avant fermeture 23:00');
    }
}
