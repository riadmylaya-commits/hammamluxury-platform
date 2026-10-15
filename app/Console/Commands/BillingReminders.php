<?php

namespace App\Console\Commands;

use App\Domain\Billing\InvoiceReminders;
use Illuminate\Console\Command;

/** Passage quotidien : factures en retard et rappels de paiement J−5 / J / J+7 / J+15 (jamais deux fois, jamais après règlement). */
class BillingReminders extends Command
{
    protected $signature = 'hl:billing-reminders';

    protected $description = 'Marque les factures en retard et envoie les rappels de paiement dus';

    public function handle(InvoiceReminders $reminders): int
    {
        $r = $reminders->run();
        $this->info($r['overdue'].' facture(s) passée(s) en retard, '.$r['reminders'].' rappel(s) envoyé(s).');

        return self::SUCCESS;
    }
}
