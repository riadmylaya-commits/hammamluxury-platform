<?php

namespace App\Domain\Billing;

use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Partner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Brouillons de facture : un brouillon par entité juridique et par mois (clé unique), recalculé à chaque passage depuis le
 * relevé provisoire ; jamais créé ni conservé pour un total ≤ 0. Un brouillon ne porte ni numéro, ni date d'émission, ni PDF.
 */
class InvoiceDrafter
{
    public function __construct(private readonly StatementBuilder $builder, private readonly BillingSettings $settings) {}

    /** Crée ou actualise le brouillon du partenaire pour le mois ; retourne null si rien n'est facturable. */
    public function draft(Partner $partner, CarbonImmutable|string $period, ?CarbonImmutable $now = null): ?Invoice
    {
        $statement = $this->builder->build($partner, $period, $now);
        $start = $this->settings->startPeriod();
        if ($start === null || $statement->period->lt($start) || ! $statement->closed) {
            return null;
        }

        return DB::transaction(function () use ($partner, $statement) {
            $invoice = Invoice::where('partner_id', $partner->id)->where('period', $statement->periodKey())->lockForUpdate()->first();
            if ($invoice !== null && ! $invoice->isDraft()) {
                return $invoice;
            }
            if ($statement->total <= 0) {
                if ($invoice !== null) {
                    $invoice->lines()->delete();
                    $invoice->delete();
                    ActivityLog::record('billing.invoice_draft_removed', null, ['partner' => $partner->id, 'period' => $statement->periodKey()], 'system');
                }

                return null;
            }
            $created = $invoice === null;
            $invoice ??= Invoice::create(['partner_id' => $partner->id, 'period' => $statement->periodKey(), 'status' => Invoice::STATUS_DRAFT,
                'currency' => config('hl.currency'), 'drafted_at' => now()]);
            $this->writeLines($invoice, $statement);
            if ($created) {
                ActivityLog::record('billing.invoice_drafted', $invoice, ['period' => $statement->periodKey(), 'total' => $invoice->total_ttc], 'system');
            }

            return $invoice->fresh();
        });
    }

    /** Tous les partenaires ayant des écritures facturables pour le mois ; retourne les brouillons existants. @return list<Invoice> */
    public function draftAll(CarbonImmutable|string $period, ?CarbonImmutable $now = null): array
    {
        $tz = config('hl.timezone');
        $period = $period instanceof CarbonImmutable ? $period : CarbonImmutable::createFromFormat('Y-m-d', $period.'-01', $tz)->startOfMonth();
        $out = [];
        foreach ($this->builder->partnersWithBillableEntries($period) as $partner) {
            $invoice = $this->draft($partner, $period, $now);
            if ($invoice !== null) {
                $out[] = $invoice;
            }
        }

        return $out;
    }

    /** Remplace les lignes d'un brouillon par celles du relevé et recalcule les montants (fiscalité selon réglage courant). */
    public function writeLines(Invoice $invoice, Statement $statement): void
    {
        $invoice->lines()->delete();
        $position = 0;
        foreach ($statement->spas as $section) {
            foreach ($section['lines'] as $line) {
                foreach ($line['entries'] as $entry) {
                    $invoice->lines()->create([
                        'ledger_entry_id' => $entry['id'],
                        'spa_id' => $line['spa_id'],
                        'booking_id' => $line['booking_id'],
                        'booking_reference' => $line['reference'],
                        'service_at' => $line['service_at'],
                        'entry_type' => $entry['type'],
                        'description' => __('billing.line_'.$entry['type'], ['status' => $line['status'] ? __('booking.status.'.$line['status']) : '']),
                        'base_amount' => $line['base'],
                        'commission_pct' => $line['rate'],
                        'amount' => round((float) $entry['amount'], 2),
                        'origin_period' => $line['origin_period'],
                        'carried' => $line['carried'],
                        'position' => ++$position,
                    ]);
                }
            }
        }
        $invoice->update($this->totals($statement->total) + ['lines_count' => $position]);
    }

    /** @return array<string, mixed> */
    public function totals(float $subtotal): array
    {
        $mode = (string) $this->settings->get('vat_mode');
        $rate = $this->settings->get('vat_rate');
        $rate = $rate === null ? null : (float) $rate;
        $vat = 0.0;
        $ttc = $subtotal;
        if ($mode === 'ht_plus_vat' && $rate !== null) {
            $vat = round($subtotal * $rate / 100, 2);
            $ttc = round($subtotal + $vat, 2);
        } elseif ($mode === 'ttc_included' && $rate !== null) {
            $vat = round($subtotal - $subtotal / (1 + $rate / 100), 2);
        }

        return ['subtotal_ht' => round($subtotal, 2), 'vat_mode' => $mode, 'vat_rate' => $rate, 'vat_amount' => $vat, 'total_ttc' => $ttc];
    }
}
