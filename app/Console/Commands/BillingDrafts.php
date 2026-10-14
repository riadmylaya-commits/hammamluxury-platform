<?php

namespace App\Console\Commands;

use App\Domain\Billing\BillingSettings;
use App\Domain\Billing\InvoiceDrafter;
use App\Filament\Shared\BillingPeriods;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** Le 1er du mois : brouillons de facture du mois précédent (aucun numéro, aucune émission, aucun PDF). */
class BillingDrafts extends Command
{
    protected $signature = 'hl:billing-drafts {--period= : Mois AAAA-MM (défaut : mois précédent)} {--dry-run : Afficher sans écrire}';

    protected $description = 'Prépare ou actualise les brouillons de facture mensuels des partenaires (sans émission)';

    public function handle(BillingSettings $settings, InvoiceDrafter $drafter): int
    {
        $tz = config('hl.timezone');
        $period = CarbonImmutable::createFromFormat('Y-m-d', ($this->option('period') ?: BillingPeriods::previous()).'-01', $tz)->startOfMonth();
        $start = $settings->startPeriod();
        if ($start === null || $period->lt($start)) {
            $this->warn('Aucun brouillon : mois antérieur au démarrage de la facturation ou démarrage non renseigné.');

            return self::SUCCESS;
        }
        if ($this->option('dry-run')) {
            $this->line('Simulation : aucun brouillon écrit.');

            return self::SUCCESS;
        }
        foreach ($drafter->draftAll($period) as $invoice) {
            $this->line(sprintf('%s · %s · %d lignes · %.2f %s', $invoice->period, $invoice->partner->company_name, $invoice->lines_count, $invoice->total_ttc, $invoice->currency));
        }

        return self::SUCCESS;
    }
}
