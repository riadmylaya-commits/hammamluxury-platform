<?php

namespace Tests\Engine;

use App\Filament\Partner\Resources\BookingResource\Pages\CalendarBookings;
use App\Models\Booking;
use Carbon\CarbonImmutable;

class BookingCalendarTest extends BookingFlowTestCase
{
    private function b(string $status, string $start, int $min = 75): Booking
    {
        $s = CarbonImmutable::parse($start);

        return new Booking(['status' => $status, 'start_at' => $s, 'end_at' => $s->addMinutes($min)]);
    }

    public function test_dots_reflect_real_activity_only(): void
    {
        $now = CarbonImmutable::parse('2026-10-10 12:00');
        $dots = CalendarBookings::dotsByDay(collect([
            $this->b('cancelled', '2026-10-10 10:00'),
            $this->b('confirmed', '2026-10-10 15:00'),
            $this->b('confirmed', '2026-10-10 16:00'),
            $this->b('cancelled', '2026-10-11 10:00'),
            $this->b('declined', '2026-10-11 11:00'),
            $this->b('completed', '2026-10-05 10:00'),
            $this->b('confirmed', '2026-10-09 10:00'),
            $this->b('no_show', '2026-10-06 10:00'),
            $this->b('waiting', '2026-10-12 10:00'),
            $this->b('completed', '2026-10-13 09:00'),
            $this->b('confirmed', '2026-10-13 18:00'),
        ]), $now);

        $this->assertSame('blue', $dots['2026-10-10'], '2 confirmées + 1 annulée → point bleu');
        $this->assertArrayNotHasKey('2026-10-11', $dots, 'Uniquement annulée/refusée → aucun point');
        $this->assertSame('gray', $dots['2026-10-05'], 'Terminée → gris');
        $this->assertSame('gray', $dots['2026-10-09'], 'Confirmée passée → gris');
        $this->assertArrayNotHasKey('2026-10-06', $dots, 'No-show → aucun point');
        $this->assertSame('blue', $dots['2026-10-12'], 'En attente à venir → bleu');
        $this->assertSame('blue', $dots['2026-10-13'], 'Terminée + à venir le même jour → bleu');
    }

    public function test_partner_can_switch_between_list_and_calendar_and_open_a_booking(): void
    {
        $owner = $this->spa->partner->user;
        $base = '/partenaire/'.$this->spa->slug.'/bookings';
        $this->actingAs($owner)->get($base)->assertOk()->assertSee('Vue Calendrier');
        $this->actingAs($owner)->get($base.'/calendrier?day=2026-10-10&month=2026-10')->assertOk()
            ->assertSee('Vue Liste')->assertSee('Octobre 2026')->assertSee('Aucune réservation ce jour-là.');
    }
}
