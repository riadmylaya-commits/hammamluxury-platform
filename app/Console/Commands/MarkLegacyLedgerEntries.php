<?php

namespace App\Console\Commands;

use App\Domain\Billing\BillingGate;
use App\Domain\Billing\BillingSettings;
use App\Models\ActivityLog;
use App\Models\LedgerEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Qualifie le périmètre de facturation des écritures du grand livre : « legacy » avant le premier mois facturable,
 * « billable » à partir de celui-ci ; une correction suit la commission initiale de sa chaîne (BillingGate::scopeFor). Lecture seule par défaut ; --apply n'écrit que la colonne billing_scope des écritures
 * non encore qualifiées (montants, dates et liens jamais modifiés) et journalise l'opération.
 */
class MarkLegacyLedgerEntries extends Command
{
    protected $signature = 'hl:billing-mark-legacy {--apply : Écrire la qualification (sinon simple rapport)}';

    protected $description = 'Qualifie les écritures du grand livre en legacy / billable selon la date de démarrage de la facturation ; rapport seul sans --apply';

    public function handle(BillingSettings $settings, BillingGate $gate): int
    {
        $start = $settings->startPeriod();
        if ($start === null) {
            $this->error('Date de démarrage (start_period) non renseignée : aucune écriture ne peut être qualifiée.');

            return self::FAILURE;
        }

        $pending = LedgerEntry::whereNull('billing_scope')->orderBy('id')->get(['id', 'created_at', 'type', 'amount', 'corrects_entry_id', 'booking_reference']);
        $scopes = $pending->mapWithKeys(fn (LedgerEntry $e) => [$e->id => $gate->scopeFor($e)]);
        $legacy = $pending->filter(fn (LedgerEntry $e) => $scopes[$e->id] === LedgerEntry::SCOPE_LEGACY);
        $billable = $pending->filter(fn (LedgerEntry $e) => $scopes[$e->id] === LedgerEntry::SCOPE_BILLABLE);
        $lateLegacy = $legacy->reject(fn (LedgerEntry $e) => $gate->isBeforeStart($e->created_at));
        $alreadyLegacy = LedgerEntry::where('billing_scope', LedgerEntry::SCOPE_LEGACY)->count();
        $alreadyBillable = LedgerEntry::where('billing_scope', LedgerEntry::SCOPE_BILLABLE)->count();

        $this->line('Premier mois facturable : '.$start->format('Y-m').' ('.config('hl.timezone').')');
        $this->line('Déjà qualifiées : legacy='.$alreadyLegacy.' billable='.$alreadyBillable);
        $this->line('Non qualifiées : '.$pending->count().' → legacy='.$legacy->count().' ('.number_format((float) $legacy->sum('amount'), 2, '.', ' ').' MAD), billable='.$billable->count());

        if ($lateLegacy->isNotEmpty()) {
            // Corrections tardives de commissions antérieures : hors factures, à régulariser avec le comptable.
            $this->warn('Corrections postérieures au démarrage rattachées à une commission legacy : '.$lateLegacy->count()
                .' ('.number_format((float) $lateLegacy->sum('amount'), 2, '.', ' ').' MAD) — '.$lateLegacy->pluck('booking_reference')->filter()->unique()->implode(', '));
        }

        if (! $this->option('apply')) {
            $this->info('Rapport seul. Relancer avec --apply pour écrire la qualification.');

            return self::SUCCESS;
        }

        // Seule la colonne billing_scope est écrite, uniquement là où elle est encore nulle : jamais de requalification.
        $wroteLegacy = $legacy->isEmpty() ? 0 : DB::table('ledger_entries')->whereIn('id', $legacy->modelKeys())->whereNull('billing_scope')->update(['billing_scope' => LedgerEntry::SCOPE_LEGACY]);
        $wroteBillable = $billable->isEmpty() ? 0 : DB::table('ledger_entries')->whereIn('id', $billable->modelKeys())->whereNull('billing_scope')->update(['billing_scope' => LedgerEntry::SCOPE_BILLABLE]);
        ActivityLog::record('billing.scope_marked', null, ['start_period' => $start->format('Y-m'), 'legacy' => $wroteLegacy, 'billable' => $wroteBillable, 'late_legacy_corrections' => $lateLegacy->count()], 'system');
        $this->info("Qualification écrite : legacy={$wroteLegacy}, billable={$wroteBillable}.");

        return self::SUCCESS;
    }
}
