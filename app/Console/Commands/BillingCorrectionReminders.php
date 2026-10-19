<?php

namespace App\Console\Commands;

use App\Domain\Billing\BillingNotifier;
use App\Domain\Billing\BillingSettings;
use App\Domain\Billing\CreditNoteService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Rappel à l'administrateur des corrections post-émission en attente depuis plus de `correction_due_days` jours
 * (une seule alerte par écriture, tracée dans billing_notifications). Aucune action automatique sur les factures.
 */
class BillingCorrectionReminders extends Command
{
    protected $signature = 'hl:billing-correction-reminders';

    protected $description = 'Signale à l’administrateur les corrections post-émission en attente au-delà du délai cible';

    public function handle(CreditNoteService $service, BillingSettings $settings, BillingNotifier $notifier): int
    {
        $alert = (string) config('hl.alert_email');
        if ($alert === '') {
            $this->warn('hl.alert_email non configuré : aucun rappel.');

            return self::SUCCESS;
        }
        $due = (int) $settings->get('correction_due_days');
        $now = CarbonImmutable::now(config('hl.timezone'));
        $count = 0;
        foreach ($service->pendingCorrections(null, null, $now) as $c) {
            if ($c['age_days'] < $due) {
                continue;
            }
            $n = $notifier->dispatch($c['invoice'], 'corrections_overdue', 'admin', $alert, ['number' => $c['invoice']->number, 'reference' => $c['entry']->booking?->reference ?? $c['entry']->booking_reference,
                'amount' => round(-(float) $c['entry']->amount, 2), 'age' => $c['age_days'], 'due' => $due, 'partner' => $c['invoice']->partner_snapshot['company_name'] ?? null], 'entry-'.$c['entry']->id);
            if ($n !== null) {
                $count++;
                $this->line($c['invoice']->number.' · écriture #'.$c['entry']->id.' · '.$c['age_days'].' j');
            }
        }
        $this->info($count.' rappel(s).');

        return self::SUCCESS;
    }
}
