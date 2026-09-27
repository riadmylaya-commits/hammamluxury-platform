<?php

namespace App\Console\Commands;

use App\Domain\Booking\BookingService;
use Illuminate\Console\Command;

class CompletePastBookings extends Command
{
    protected $signature = 'hl:complete-past';

    protected $description = 'Passe en « terminée » les réservations confirmées dont l’heure de fin est dépassée';

    public function handle(BookingService $bookings): int
    {
        $ids = $bookings->completePast();
        $this->info(count($ids).' réservation(s) terminée(s)'.($ids ? ' : '.implode(', ', $ids) : ''));

        return self::SUCCESS;
    }
}
