<?php

namespace App\Console\Commands;

use App\Domain\Booking\BookingService;
use Illuminate\Console\Command;

class ExpireWaitingBookings extends Command
{
    protected $signature = 'hl:expire-waiting';

    protected $description = 'Expire les demandes en attente dont le délai de réponse partenaire est dépassé et libère leurs créneaux';

    public function handle(BookingService $bookings): int
    {
        $ids = $bookings->expireWaiting();
        $this->info(count($ids).' demande(s) expirée(s)'.($ids ? ' : '.implode(', ', $ids) : ''));

        return self::SUCCESS;
    }
}
