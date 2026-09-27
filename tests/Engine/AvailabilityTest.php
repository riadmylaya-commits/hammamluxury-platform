<?php

namespace Tests\Engine;

use App\Domain\Booking\BookingException;
use Carbon\CarbonImmutable;

class AvailabilityTest extends BookingFlowTestCase
{
    public function test_slots_respect_duration_break_and_closing(): void
    {
        $av = $this->availability(['treatment' => $this->hm->id, 'party' => 2, 'extras' => [$this->cr->id]]);
        $t = $av['times'];
        $this->assertSame('', $av['reason'], 'Créneaux disponibles : aucun motif de refus');
        $this->assertTrue(in_array('09:00', $t, true) && in_array('11:30', $t, true) && ! in_array('12:00', $t, true) && ! in_array('14:00', $t, true) && in_array('16:00', $t, true) && in_array('20:30', $t, true) && ! in_array('21:00', $t, true), 'H+M couple + crânien (135 min) : 11:30 OK, 12:00 refusé (pause 14:00), 20:30 OK, 21:00 refusé (fermeture 23:00) — '.implode(' ', $t));
    }

    public function test_not_enough_cabins_explained(): void
    {
        $av = $this->availability(['treatment' => $this->m->id, 'party' => 3]);
        $this->assertSame([], $av['times'], 'Massage 3 pers avec 2 cabines → aucun créneau');
        $this->assertTrue(str_contains($av['reason'], 'Cabine de massage') && str_contains($av['reason'], '3 personne(s)'), 'Indisponibilité : le moteur explique la ressource et le nombre de personnes — '.$av['reason']);
    }

    public function test_closed_day_distinct_reason(): void
    {
        $sunday = CarbonImmutable::parse($this->day)->next(CarbonImmutable::SUNDAY)->format('Y-m-d');
        $av = $this->availability(['treatment' => $this->hm->id, 'party' => 2], $sunday);
        $this->assertSame([], $av['times'], 'Dimanche fermé → aucun créneau');
        $this->assertStringContainsString('heures d’ouverture', $av['reason'], 'Fermeture : explication distincte d’un manque de capacité');
    }

    public function test_invalid_date_rejected(): void
    {
        try {
            $this->bookings->prepare($this->spa, ['date' => '2026-13-45', 'time' => '10:00', 'treatment' => $this->hm->id, 'party' => 1]);
            $this->fail('Date invalide acceptée');
        } catch (BookingException $e) {
            $this->assertSame('datetime', $e->reason, 'Date invalide refusée');
        }
    }

    public function test_lead_time_and_step(): void
    {
        $this->spa->update(['min_lead_minutes' => 120, 'slot_step_minutes' => 15]);
        $this->reload();
        $today = CarbonImmutable::now()->format('Y-m-d');
        $av = $this->engine->availability($this->spa, $this->day, $this->quote(['treatment' => $this->h->id, 'party' => 1])['items']);
        $this->assertContains('09:15', $av['times'], 'Pas de 15 minutes respecté');
        try {
            $this->bookings->prepare($this->spa, ['date' => $today, 'time' => CarbonImmutable::now()->addMinutes(30)->format('H:i'), 'treatment' => $this->h->id, 'party' => 1]);
            $this->fail('Créneau trop proche accepté');
        } catch (BookingException $e) {
            $this->assertSame('too_soon', $e->reason, 'Délai minimal de réservation appliqué');
        }
    }

    public function test_slots_shrink_after_booking_and_return_after_release(): void
    {
        $b = $this->book('Salle soin 16:00', '16:00', [['hammam-soin', 1]], true);
        $t = $this->availability(['treatment' => $this->hs->id, 'party' => 1])['times'];
        $this->assertTrue(! in_array('16:00', $t, true) && ! in_array('16:30', $t, true) && in_array('17:00', $t, true), 'Salle de soin occupée 16:45–17:35 : 16:00/16:30 retirés, 17:00 disponible (hammam 17:00, soin 17:45)');
        $this->cancel($b);
        $t = $this->availability(['treatment' => $this->hs->id, 'party' => 1])['times'];
        $this->assertContains('16:00', $t, 'Après annulation : 16:00 à nouveau disponible');
    }
}
