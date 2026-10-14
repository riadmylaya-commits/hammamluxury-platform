<?php

namespace App\Domain\Billing;

use App\Jobs\GenerateInvoicePdf;
use App\Models\ActivityLog;
use App\Models\CommissionDispute;
use App\Models\Invoice;
use App\Models\InvoiceSequence;
use App\Models\Partner;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Émission d'une facture en deux étapes. Étape 1 (ici, une seule transaction, verrou sur le brouillon) : contrôles serveur,
 * lignes recalculées et figées, numéro attribué sous verrou de séquence, date d'émission = date réelle, échéance = +due_days,
 * identités figées. Étape 2 (job rejouable après commit) : PDF puis notification « facture émise » uniquement quand le PDF est prêt.
 * Un échec de PDF ou d'e-mail ne crée jamais une seconde facture : l'étape 1 est déjà commise et les écritures sont rattachées.
 */
class InvoiceIssuer
{
    public function __construct(
        private readonly BillingGate $gate,
        private readonly BillingSettings $settings,
        private readonly StatementBuilder $builder,
        private readonly InvoiceDrafter $drafter,
    ) {}

    /** Motifs empêchant l'émission de ce brouillon maintenant (vide = émission possible). @return list<string> */
    public function blockers(Invoice $invoice, ?CarbonImmutable $now = null): array
    {
        $tz = config('hl.timezone');
        $now ??= CarbonImmutable::now($tz);
        $out = [];
        if (! $invoice->isDraft()) {
            return ['already_issued'];
        }
        $out = [...$this->gate->globalBlockers(), ...$this->gate->partnerBlockers($invoice->partner)];
        $period = CarbonImmutable::createFromFormat('Y-m-d', $invoice->period.'-01', $tz)->startOfMonth();
        $issueFrom = $period->addMonth()->day(max(1, min(28, (int) $this->settings->get('issue_day'))))->startOfDay();
        if ($now->lt($issueFrom)) {
            $out[] = 'issue_day_not_reached';
        }
        if (CommissionDispute::pendingCorrection()->where('partner_id', $invoice->partner_id)->exists()) {
            $out[] = 'corrections_pending';
        }
        $statement = $this->builder->build($invoice->partner, $period, $now);
        if ($statement->total <= 0) {
            $out[] = 'total_not_positive';
        }

        return array_values(array_unique($out));
    }

    /** @throws InvoiceException */
    public function issue(Invoice $invoice, User $admin, ?CarbonImmutable $now = null): Invoice
    {
        if (! $admin->isAdmin()) {
            throw new InvoiceException('forbidden');
        }
        $tz = config('hl.timezone');
        $now ??= CarbonImmutable::now($tz);

        $issued = DB::transaction(function () use ($invoice, $admin, $now, $tz) {
            $locked = Invoice::whereKey($invoice->id)->lockForUpdate()->with('partner')->first();
            if ($locked === null) {
                throw new InvoiceException('not_found');
            }
            $blockers = $this->blockers($locked, $now);
            if ($blockers !== []) {
                throw new InvoiceException($blockers === ['already_issued'] ? 'already_issued' : 'blocked', $blockers);
            }
            $period = CarbonImmutable::createFromFormat('Y-m-d', $locked->period.'-01', $tz)->startOfMonth();
            $statement = $this->builder->build($locked->partner, $period, $now);
            try {
                $this->drafter->writeLines($locked, $statement);
            } catch (UniqueConstraintViolationException) {
                throw new InvoiceException('entry_already_invoiced');
            }
            $number = sprintf('HL-%d-%06d', $now->year, InvoiceSequence::next($now->year, 'invoice'));
            $locked->update([
                'number' => $number,
                'status' => Invoice::STATUS_ISSUED,
                'issued_at' => $now,
                'due_at' => $now->addDays((int) $this->settings->get('due_days'))->toDateString(),
                'issued_by' => $admin->id,
                'issuer_snapshot' => $this->settings->issuer(),
                'partner_snapshot' => $this->partnerSnapshot($locked->partner),
                'pdf_status' => Invoice::PDF_PENDING,
                'pdf_attempts' => 0,
                'pdf_error' => null,
            ]);
            ActivityLog::record('billing.invoice_issued', $locked, ['number' => $number, 'period' => $locked->period, 'total' => $locked->total_ttc,
                'lines' => $locked->lines_count, 'partner' => $locked->partner->company_name], 'admin');

            return $locked->fresh();
        });

        GenerateInvoicePdf::dispatch($issued->id);

        return $issued;
    }

    /** @return array<string, mixed> */
    private function partnerSnapshot(Partner $partner): array
    {
        return [
            'company_name' => $partner->company_name, 'legal_form' => $partner->legal_form, 'ice' => $partner->ice_not_applicable ? null : $partner->ice,
            'ice_not_applicable' => (bool) $partner->ice_not_applicable, 'tax_id' => $partner->tax_id, 'registry_number' => $partner->registry_number,
            'billing_address' => $partner->billing_address, 'billing_city' => $partner->billing_city, 'billing_email' => $partner->billing_email ?: $partner->user?->email,
        ];
    }
}
