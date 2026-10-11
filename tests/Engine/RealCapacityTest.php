<?php

namespace Tests\Engine;

use App\Domain\Booking\BookingException;

/**
 * Lot 1 « capacité réelle » : rotation entre deux clients, cabine + praticien réservés ensemble,
 * massage à 4 mains, étapes en parallèle, établissement sans horaires = fermé.
 *
 * Établissement : hammam collectif 6 (sans rotation), 3 cabines (rotation 15 min),
 * 2 praticiennes, 1 salle de gommage (rotation 0).
 */
class RealCapacityTest extends RealCapacityTestCase
{
    public function test_rotation_blocks_cabin_but_not_billed_duration(): void
    {
        $this->book('R1 cabine 1', '10:00', [['massage-cabine', 1]], true);
        $this->book('R2 cabine 2', '10:00', [['massage-cabine', 1]], true);
        $r3 = $this->book('R3 cabine 3', '10:00', [['massage-cabine', 1]], true);
        $this->assertSame(60, $r3->duration_min, 'La rotation n’allonge pas la durée facturée');
        $this->assertSame('11:00', $r3->end_at->format('H:i'));
        $this->assertSame('11:00', $this->activeAllocations($r3)[0]->end_at->format('H:i'), 'L’allocation enregistre la fin réelle, sans rotation');
        $this->book('R4 à 11:00 pile (rotation 15 min)', '11:00', [['massage-cabine', 1]], false);
        $this->book('R5 à 11:10', '11:10', [['massage-cabine', 1]], false);
        $this->book('R6 à 11:15', '11:15', [['massage-cabine', 1]], true);
    }

    public function test_rotation_also_applies_before_an_existing_booking(): void
    {
        foreach ([1, 2, 3] as $i) {
            $this->book("A$i cabine", '12:00', [['massage-cabine', 1]], true);
        }
        $this->book('B 10:50–11:50 : fin + 15 min chevauche 12:00', '10:50', [['massage-cabine', 1]], false);
        $this->book('C 10:45–11:45 : rotation finit à 12:00', '10:45', [['massage-cabine', 1]], true);
    }

    public function test_hammam_pool_without_rotation_is_unchanged(): void
    {
        $this->book('H1 6 personnes', '10:00', [['hammam-simple', 6]], true);
        $this->book('H2 juste après', '10:45', [['hammam-simple', 6]], true);
    }

    public function test_cabin_and_therapist_booked_together(): void
    {
        $this->book('M1 massage 60', '10:00', [['massage-60', 1]], true);
        $m2 = $this->book('M2 massage 60', '10:00', [['massage-60', 1]], true);
        $this->assertCount(2, $this->activeAllocations($m2), 'Cabine + praticienne alloués ensemble');
        $this->book('M3 3e cabine libre mais plus de praticienne', '10:00', [['massage-60', 1]], false);
        $this->book('M4 cabine seule sans praticienne : 3e cabine OK', '10:00', [['massage-cabine', 1]], true);
        $this->book('M5 praticienne libre à 11:00 mais cabines en rotation', '11:00', [['massage-60', 1]], false);
        $this->book('M6 11:15 tout libre', '11:15', [['massage-60', 1]], true);
    }

    public function test_couple_massage_needs_two_therapists(): void
    {
        $this->book('C1 couple = 2 cabines + 2 praticiennes', '16:00', [['massage-60', 2]], true);
        $this->book('C2 plus aucune praticienne', '16:30', [['massage-60', 1]], false);
    }

    public function test_four_hands_massage_takes_both_therapists(): void
    {
        $m = $this->book('Q1 4 mains', '10:00', [['massage-4-mains', 1]], true);
        $this->assertCount(3, $this->activeAllocations($m), '1 cabine + 2 praticiennes');
        $this->book('Q2 aucun praticien restant', '10:30', [['massage-60', 1]], false);
        $this->book('Q3 cabine seule reste possible', '10:30', [['massage-cabine', 1]], true);
    }

    public function test_parallel_step_during_hammam(): void
    {
        $b = $this->book('P1 hammam + gommage en parallèle', '10:00', [['hammam-gommage', 1]], true);
        $this->assertSame(45, $b->duration_min, 'Durée = 45 (le gommage se déroule pendant le hammam)');
        $allocs = $this->activeAllocations($b);
        $this->assertCount(3, $allocs, 'hammam + table + praticienne');
        $this->assertSame(['10:00', '10:00', '10:00'], array_map(fn ($a) => $a->start_at->format('H:i'), $allocs), 'Toutes les étapes démarrent à 10:00');
        $this->book('P2 table occupée 10:00–10:20', '10:10', [['hammam-gommage', 1]], false);
        $this->book('P3 table libre à 10:20, hammam 5 places restantes', '10:20', [['hammam-gommage', 1]], true);
    }

    public function test_sequential_ritual_frees_hammam_then_cabin(): void
    {
        $this->book('S1 rituel 10:00 (hammam 10:00–10:40, cabine+prat 10:40–11:40)', '10:00', [['rituel', 1]], true);
        $this->book('S2 hammam 5 places après 10:40', '10:40', [['hammam-simple', 5]], true);
        $this->book('S3 2 praticiennes dès 10:40 ? il en reste 1', '10:40', [['massage-60', 2]], false);
        $this->book('S4 1 praticienne restante', '10:40', [['massage-60', 1]], true);
    }

    public function test_staff_required_but_no_therapist_configured(): void
    {
        $this->spa->resources()->whereHas('type', fn ($q) => $q->where('kind', 'therapist'))->delete();
        $this->reload();
        $this->book('N1 massage avec praticien requis, aucun praticien', '10:00', [['massage-60', 1]], false);
        $this->book('N2 cabine seule reste réservable', '10:00', [['massage-cabine', 1]], true);
        $this->assertSame([], $this->availability(['participants' => [['treatment' => 'massage-60', 'party' => 1]]])['times']);
    }

    public function test_no_hours_means_closed_everywhere(): void
    {
        $this->spa->hours()->delete();
        $this->reload();
        $this->book('Z1 sans horaires', '10:00', [['hammam-simple', 1]], false);
        $av = $this->availability(['participants' => [['treatment' => 'hammam-simple', 'party' => 1]]]);
        $this->assertSame([], $av['times']);
        $this->assertSame(__('booking.not_configured'), $av['reason']);
        try {
            $this->bookings->book($this->spa, $this->at('10:00'), [['treatment' => 'hammam-simple', 'party' => 1]], ['first_name' => 'T', 'last_name' => 'C', 'email' => 'c@example.test', 'phone' => '0600000000']);
            $this->fail('Réservation acceptée sans horaires');
        } catch (BookingException $e) {
            $this->assertStringContainsString('horaires', $e->getMessage());
        }
    }

    public function test_availability_reflects_rotation_and_staff(): void
    {
        $this->book('M1', '10:00', [['massage-60', 1]], true);
        $this->book('M2', '10:00', [['massage-60', 1]], true);
        $times = $this->availability(['participants' => [['treatment' => 'massage-60', 'party' => 1]]])['times'];
        $this->assertNotContains('10:00', $times, 'Plus de praticienne');
        $this->assertNotContains('10:30', $times);
        $this->assertContains('11:00', $times, '3e cabine libre + praticiennes libres à 11:00');
        $this->assertContains('09:00', $times, '09:00–10:00 + rotation → 3e cabine encore libre à 10:00');

        $this->book('R 3e cabine', '10:00', [['massage-cabine', 1]], true);
        $times = $this->availability(['participants' => [['treatment' => 'massage-60', 'party' => 1]]])['times'];
        $this->assertNotContains('09:00', $times, 'Rotation 10:00–10:15 chevaucherait les 3 cabines occupées');
        $this->assertNotContains('11:00', $times, 'Cabines en rotation jusqu’à 11:15');
        $this->assertContains('11:30', $times);
    }
}
