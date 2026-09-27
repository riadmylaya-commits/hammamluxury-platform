<?php

namespace Tests\Engine;

use App\Domain\Booking\BookingException;
use App\Models\Booking;
use App\Models\Extra;
use App\Models\Treatment;

/**
 * Fixture du parcours client : hammam collectif 6 (pool), 2 cabines de massage (unit), 1 salle de soin (unit).
 * Prestations : Hammam (150 / couple 350 / groupe 150 p.p.), Massage 400 (max 4), Hammam + Massage 600 / couple 1100,
 * Hammam + Soin 550. Extras sur Hammam + Massage : Massage crânien +125/+15 min p.p., Thé 30 ×3 max non p.p.
 */
abstract class BookingFlowTestCase extends EngineTestCase
{
    protected Treatment $h;

    protected Treatment $m;

    protected Treatment $hm;

    protected Treatment $hs;

    protected Extra $cr;

    protected Extra $the;

    protected array $customer = ['first_name' => 'Test', 'last_name' => 'Client', 'email' => 'client@example.test', 'phone' => '0600000000', 'hotel' => 'Riad Test'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->spa('hl-test-flow', 'HL TEST Flow');
        $h = $this->type('hammam', 'Hammam');
        $m = $this->type('massage', 'Cabine de massage', 'unit');
        $s = $this->type('soin', 'Salle de soin', 'unit');
        $this->resource($h, 'Hammam', 6);
        $this->resource($m, 'Cabine 1', 1);
        $this->resource($m, 'Cabine 2', 1);
        $this->resource($s, 'Salle soin', 1);
        $this->h = $this->treatment('hammam', 'Hammam traditionnel', [['type' => 'hammam', 'duration' => 45]], 6, ['price_solo' => 150, 'price_couple' => 350, 'price_group' => 150]);
        $this->m = $this->treatment('massage', 'Massage relaxant', [['type' => 'massage', 'duration' => 60]], 4, ['price_solo' => 400]);
        $this->hm = $this->treatment('hammam-massage', 'Hammam + Massage', [['type' => 'hammam', 'duration' => 45], ['type' => 'massage', 'duration' => 75, 'offset' => 45]], 4, ['price_solo' => 600, 'price_couple' => 1100]);
        $this->hs = $this->treatment('hammam-soin', 'Hammam + Soin visage', [['type' => 'hammam', 'duration' => 45], ['type' => 'soin', 'duration' => 50, 'offset' => 45]], 2, ['price_solo' => 550]);
        $this->cr = $this->extra($this->hm, 'Massage crânien', 125, 15);
        $this->the = $this->extra($this->hm, 'Thé à la menthe', 30, 0, false, 3);
        $this->reload();
    }

    protected function quote(array $req): array
    {
        return $this->quotes->fromRequest($this->spa, $req);
    }

    protected function intent(string $time, array $sel): array
    {
        return $this->bookings->prepare($this->spa, ['date' => $this->day, 'time' => $time] + $sel);
    }

    /** @return array{ok: bool, booking: ?Booking, error: string, reason: string} */
    protected function submit(array $prepared, array $customer = []): array
    {
        try {
            $b = $this->bookings->confirmIntent($this->spa, $prepared['intent']['token'], $customer ?: $this->customer);

            return ['ok' => true, 'booking' => $b, 'error' => '', 'reason' => ''];
        } catch (BookingException $e) {
            return ['ok' => false, 'booking' => null, 'error' => $e->getMessage(), 'reason' => $e->reason];
        }
    }
}
