<?php

namespace App\Console\Commands;

use App\Domain\Booking\CancellationService;
use Illuminate\Console\Command;

class SendCancellationReminders extends Command
{
    protected $signature = 'hl:cancellation-reminders';

    protected $description = 'Rappelle à HammamLuxury les demandes d’annulation partenaire encore sans décision à la veille du rendez-vous';

    public function handle(CancellationService $cancellations): int
    {
        $ids = $cancellations->remindAdmins();
        $this->info(count($ids).' rappel(s) envoyé(s)'.($ids ? ' : '.implode(', ', $ids) : ''));

        return self::SUCCESS;
    }
}
