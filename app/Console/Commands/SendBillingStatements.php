<?php

namespace App\Console\Commands;

use App\Domain\Billing\BillingNotifier;
use App\Domain\Billing\BillingSettings;
use App\Domain\Billing\StatementBuilder;
use App\Models\ActivityLog;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Le 1er du mois : notifie chaque partenaire que son relevé provisoire du mois précédent est consultable.
 * Aucune facture, aucun document : seulement une notification traçable par partenaire et par période (jamais deux fois).
 */
class SendBillingStatements extends Command
{
    protected $signature = 'hl:billing-statements {--period= : Mois AAAA-MM (défaut : mois précédent)} {--dry-run : Afficher sans notifier}';

    protected $description = 'Notifie les partenaires de la disponibilité de leur relevé provisoire de commissions (sans émission)';

    public function handle(BillingSettings $settings, StatementBuilder $builder, BillingNotifier $notifier): int
    {
        $tz = config('hl.timezone');
        $period = $this->option('period')
            ? CarbonImmutable::createFromFormat('Y-m-d', $this->option('period').'-01', $tz)->startOfMonth()
            : CarbonImmutable::now($tz)->subMonthNoOverflow()->startOfMonth();
        $start = $settings->startPeriod();
        if ($start === null || $period->lt($start)) {
            $this->info('Aucun relevé : premier mois facturable non renseigné ou période antérieure au démarrage.');

            return self::SUCCESS;
        }

        $notified = 0;
        foreach ($builder->partnersWithBillableEntries($period) as $partner) {
            $statement = $builder->build($partner, $period);
            if ($statement->total <= 0 && $statement->excluded === []) {
                continue;
            }
            $recipient = $partner->billing_email ?: $partner->user?->email;
            $this->line(sprintf('%s · %s · %.2f (%d lignes, %d exclues)%s', $period->format('Y-m'), $partner->company_name, $statement->total, count($statement->lines()), count($statement->excluded), $recipient ? '' : ' · aucun destinataire'));
            if ($this->option('dry-run') || ! $recipient) {
                continue;
            }
            $n = $notifier->dispatch($partner, 'statement_available', 'partner', $recipient, ['period' => $period->format('Y-m'), 'total' => $statement->total], $period->format('Y-m'));
            if ($n !== null) {
                $notified++;
                ActivityLog::record('billing.statement_notified', $partner, ['period' => $period->format('Y-m'), 'total' => $statement->total], 'system');
            }
        }
        $this->info("Notifications créées : {$notified}");

        return self::SUCCESS;
    }
}
