<?php

namespace App\Domain\Billing;

use App\Jobs\GenerateCreditNotePdf;
use App\Models\ActivityLog;
use App\Models\CommissionDispute;
use App\Models\CreditNote;
use App\Models\CreditNoteLine;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceSequence;
use App\Models\LedgerEntry;
use App\Models\Partner;
use App\Models\PartnerCredit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Avoirs : seul canal des corrections négatives sur une facture déjà émise. Une écriture de correction du grand livre
 * (`commission_reversal` / `commission_adjust` négative dont la commission de base est facturée) alimente au plus un avoir
 * (clé unique). Brouillon par l'Admin, émission en deux étapes sous verrou (numéro HL-A dans la transaction, PDF par job
 * rejouable), avoir émis immuable ; une erreur se corrige par un avoir d'annulation lié. L'effet sur la facture passe par
 * InvoiceBalance ; un avoir dépassant le reste dû crée un crédit partenaire (jamais imputé ni remboursé automatiquement).
 */
class CreditNoteService
{
    public function __construct(
        private readonly BillingGate $gate,
        private readonly BillingSettings $settings,
        private readonly InvoiceBalance $balance,
    ) {}

    /**
     * File « corrections post-émission » : écritures négatives acquises après facturation de la réservation, encore sans
     * facture ni avoir. Chaque élément : entry, invoice (facture d'origine), invoice_line, dispute, age_days.
     *
     * @return Collection<int, array{entry: LedgerEntry, invoice: Invoice, invoice_line: ?InvoiceLine, dispute: ?CommissionDispute, age_days: int}>
     */
    public function pendingCorrections(?Partner $partner = null, ?Invoice $invoice = null, ?CarbonImmutable $now = null): Collection
    {
        $now ??= CarbonImmutable::now(config('hl.timezone'));
        $baseLines = InvoiceLine::query()->with('invoice')
            ->whereHas('invoice', fn ($q) => $q->issued()->when($partner, fn ($q) => $q->where('partner_id', $partner->id))->when($invoice, fn ($q) => $q->whereKey($invoice->id)))
            ->whereNotNull('booking_id')->where('amount', '>', 0)
            ->orderBy('id')->get()->keyBy('booking_id');
        if ($baseLines->isEmpty()) {
            return collect();
        }
        $entries = LedgerEntry::query()
            ->whereIn('type', ['commission_reversal', 'commission_adjust'])
            ->where('amount', '<', 0)
            ->where('billing_scope', LedgerEntry::SCOPE_BILLABLE)
            ->whereIn('booking_id', $baseLines->keys()->all())
            ->whereNotIn('id', InvoiceLine::query()->select('ledger_entry_id'))
            ->whereNotIn('id', CreditNoteLine::query()->select('ledger_entry_id'))
            ->with(['booking.spa', 'spa', 'partner'])
            ->orderBy('id')->get();
        $disputes = CommissionDispute::query()->whereIn('booking_id', $entries->pluck('booking_id')->unique()->all())
            ->where('status', 'accepted')->get()->keyBy('booking_id');

        return $entries->map(function (LedgerEntry $e) use ($baseLines, $disputes, $now) {
            /** @var InvoiceLine $line */
            $line = $baseLines[$e->booking_id];

            return ['entry' => $e, 'invoice' => $line->invoice, 'invoice_line' => $line, 'dispute' => $disputes[$e->booking_id] ?? null,
                'age_days' => (int) CarbonImmutable::parse($e->created_at)->startOfDay()->diffInDays($now->startOfDay())];
        })->values();
    }

    /**
     * Brouillon d'avoir sur une facture émise, à partir d'écritures de correction en attente de cette facture.
     *
     * @param  list<int>  $entryIds
     *
     * @throws CreditNoteException
     */
    public function draft(Invoice $invoice, User $admin, array $entryIds, string $reason, ?CommissionDispute $dispute = null): CreditNote
    {
        if (! $admin->isAdmin()) {
            throw new CreditNoteException('forbidden');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new CreditNoteException('reason_required');
        }
        $entryIds = array_values(array_unique(array_map('intval', $entryIds)));
        if ($entryIds === []) {
            throw new CreditNoteException('no_entries');
        }

        try {
            return DB::transaction(function () use ($invoice, $admin, $entryIds, $reason, $dispute) {
                $locked = Invoice::whereKey($invoice->id)->lockForUpdate()->first();
                if ($locked === null || ! $locked->isIssued()) {
                    throw new CreditNoteException('invoice_not_issued');
                }
                if ($locked->isCancelled()) {
                    throw new CreditNoteException('invoice_cancelled');
                }
                $pending = $this->pendingCorrections(null, $locked)->keyBy(fn (array $c) => $c['entry']->id);
                $missing = array_diff($entryIds, $pending->keys()->all());
                if ($missing !== []) {
                    throw new CreditNoteException('entry_not_pending', ['ids' => implode(', ', $missing)]);
                }
                $subtotal = 0.0;
                foreach ($entryIds as $id) {
                    $subtotal = round($subtotal - (float) $pending[$id]['entry']->amount, 2);
                }
                if ($subtotal <= 0) {
                    throw new CreditNoteException('amount_not_positive');
                }
                $this->assertWithinInvoice($locked, $subtotal);
                $note = CreditNote::create(['invoice_id' => $locked->id, 'partner_id' => $locked->partner_id, 'status' => CreditNote::STATUS_DRAFT, 'currency' => $locked->currency,
                    'reason' => mb_substr($reason, 0, 500), 'dispute_id' => $dispute?->id, 'created_by' => $admin->id] + $this->totals($locked, $subtotal));
                $position = 0;
                foreach ($entryIds as $id) {
                    /** @var LedgerEntry $e */
                    $e = $pending[$id]['entry'];
                    $note->lines()->create([
                        'ledger_entry_id' => $e->id, 'invoice_line_id' => $pending[$id]['invoice_line']?->id, 'booking_id' => $e->booking_id,
                        'booking_reference' => $e->booking?->reference ?? $e->booking_reference, 'spa_id' => $e->spa_id ?? $e->booking?->spa_id,
                        'entry_type' => $e->type, 'description' => __('billing.line_'.$e->type, ['status' => $e->booking?->status ? __('booking.status.'.$e->booking->status) : '']),
                        'amount' => round(-(float) $e->amount, 2), 'position' => ++$position,
                    ]);
                }
                $note->update(['lines_count' => $position]);
                ActivityLog::record('billing.credit_note_drafted', $note, ['invoice' => $locked->number, 'total' => $note->total_ttc, 'entries' => $entryIds], 'admin');

                return $note->fresh(['lines']);
            });
        } catch (UniqueConstraintViolationException) {
            throw new CreditNoteException('entry_already_credited');
        }
    }

    /** Abandon d'un brouillon (jamais d'un avoir émis). @throws CreditNoteException */
    public function discard(CreditNote $note, User $admin): void
    {
        if (! $admin->isAdmin()) {
            throw new CreditNoteException('forbidden');
        }
        DB::transaction(function () use ($note) {
            $locked = CreditNote::whereKey($note->id)->lockForUpdate()->first();
            if ($locked === null || ! $locked->isDraft()) {
                throw new CreditNoteException('already_issued');
            }
            ActivityLog::record('billing.credit_note_discarded', $locked, ['invoice' => $locked->invoice?->number, 'total' => $locked->total_ttc], 'admin');
            $locked->lines()->delete();
            $locked->delete();
        });
    }

    /** Motifs empêchant l'émission maintenant (vide = possible). @return list<string> */
    public function blockers(CreditNote $note): array
    {
        if (! $note->isDraft()) {
            return ['already_issued'];
        }
        $out = [...$this->gate->globalBlockers(), ...$this->gate->partnerBlockers($note->partner)];
        $invoice = $note->invoice;
        if ($invoice === null || ! $invoice->isIssued()) {
            $out[] = 'invoice_not_issued';
        } elseif ($invoice->isCancelled()) {
            $out[] = 'invoice_cancelled';
        } elseif (round((float) $invoice->credited_amount + (float) $note->total_ttc, 2) > round((float) $invoice->total_ttc, 2)) {
            $out[] = 'exceeds_invoice';
        }
        if ($note->lines_count < 1) {
            $out[] = 'no_entries';
        }

        return array_values(array_unique($out));
    }

    /** Émission : numéro HL-A sous verrou de séquence, identités figées, solde de la facture recalculé, crédit partenaire si excédent. @throws CreditNoteException */
    public function issue(CreditNote $note, User $admin, ?CarbonImmutable $now = null): CreditNote
    {
        if (! $admin->isAdmin()) {
            throw new CreditNoteException('forbidden');
        }
        $now ??= CarbonImmutable::now(config('hl.timezone'));

        $issued = DB::transaction(function () use ($note, $admin, $now) {
            $invoice = Invoice::whereKey($note->invoice_id)->lockForUpdate()->with('partner')->first();
            $locked = CreditNote::whereKey($note->id)->lockForUpdate()->with(['partner', 'lines'])->first();
            if ($invoice === null || $locked === null) {
                throw new CreditNoteException('not_found');
            }
            $locked->setRelation('invoice', $invoice);
            $blockers = $this->blockers($locked);
            if ($blockers !== []) {
                throw new CreditNoteException($blockers === ['already_issued'] ? 'already_issued' : 'blocked', ['blockers' => implode(', ', $blockers)]);
            }
            $creditedBefore = round((float) $invoice->credited_amount, 2);
            $creditedAfter = round($creditedBefore + (float) $locked->total_ttc, 2);
            $paid = round((float) $invoice->paid_amount, 2);
            $excess = round($this->balance->excess($invoice, $paid, $creditedAfter) - $this->balance->excess($invoice, $paid, $creditedBefore), 2);
            $number = sprintf('HL-A-%d-%05d', $now->year, InvoiceSequence::next($now->year, 'credit_note'));
            $this->balance->apply($invoice, $paid, $creditedAfter, $now);
            $locked->update([
                'number' => $number, 'status' => CreditNote::STATUS_ISSUED, 'issued_at' => $now, 'issued_by' => $admin->id,
                'issuer_snapshot' => $invoice->issuer_snapshot ?? $this->settings->issuer(), 'partner_snapshot' => $invoice->partner_snapshot,
                'invoice_balance_after' => $invoice->balance, 'pdf_status' => CreditNote::PDF_PENDING, 'pdf_attempts' => 0, 'pdf_error' => null,
            ]);
            if ($excess > 0) {
                PartnerCredit::create(['partner_id' => $invoice->partner_id, 'source' => PartnerCredit::SOURCE_CREDIT_NOTE, 'credit_note_id' => $locked->id,
                    'amount' => $excess, 'currency' => $invoice->currency, 'status' => PartnerCredit::STATUS_OPEN, 'created_by' => $admin->id]);
            }
            if ($locked->dispute_id !== null) {
                CommissionDispute::whereKey($locked->dispute_id)->update(['credit_note_id' => $locked->id]);
            }
            ActivityLog::record('billing.credit_note_issued', $locked, ['number' => $number, 'invoice' => $invoice->number, 'total' => $locked->total_ttc,
                'lines' => $locked->lines_count, 'balance' => $invoice->balance, 'status' => $invoice->status, 'credit' => $excess > 0 ? $excess : null], 'admin');

            return $locked->fresh();
        });

        GenerateCreditNotePdf::dispatch($issued->id);

        return $issued;
    }

    /**
     * Annulation d'un avoir émis par erreur : avoir d'annulation lié (total négatif, sans ligne), une seule fois, refusé si le
     * crédit partenaire issu de l'avoir a déjà été consommé.
     *
     * @throws CreditNoteException
     */
    public function reverse(CreditNote $original, User $admin, string $reason, ?CarbonImmutable $now = null): CreditNote
    {
        if (! $admin->isAdmin()) {
            throw new CreditNoteException('forbidden');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new CreditNoteException('reason_required');
        }
        $now ??= CarbonImmutable::now(config('hl.timezone'));

        try {
            $reversal = DB::transaction(function () use ($original, $admin, $reason, $now) {
                $invoice = Invoice::whereKey($original->invoice_id)->lockForUpdate()->first();
                $locked = CreditNote::whereKey($original->id)->lockForUpdate()->first();
                if ($invoice === null || $locked === null) {
                    throw new CreditNoteException('not_found');
                }
                if (! $locked->isIssued() || $locked->isReversal()) {
                    throw new CreditNoteException('cannot_reverse');
                }
                if ($locked->reversedBy()->exists()) {
                    throw new CreditNoteException('already_reversed');
                }
                $credit = PartnerCredit::where('credit_note_id', $locked->id)->lockForUpdate()->first();
                if ($credit !== null && round($credit->applied_amount + $credit->refunded_amount, 2) > 0) {
                    throw new CreditNoteException('credit_already_used');
                }
                $creditedAfter = round((float) $invoice->credited_amount - (float) $locked->total_ttc, 2);
                $this->balance->apply($invoice, (float) $invoice->paid_amount, $creditedAfter, $now);
                $credit?->update(['status' => PartnerCredit::STATUS_REVERSED, 'closed_by' => $admin->id, 'closed_at' => $now, 'note' => mb_substr($reason, 0, 500)]);
                $number = sprintf('HL-A-%d-%05d', $now->year, InvoiceSequence::next($now->year, 'credit_note'));
                $reversal = CreditNote::create([
                    'number' => $number, 'invoice_id' => $invoice->id, 'partner_id' => $invoice->partner_id, 'status' => CreditNote::STATUS_ISSUED,
                    'currency' => $invoice->currency, 'subtotal_ht' => -(float) $locked->subtotal_ht, 'vat_mode' => $locked->vat_mode, 'vat_rate' => $locked->vat_rate,
                    'vat_amount' => -(float) $locked->vat_amount, 'total_ttc' => -(float) $locked->total_ttc, 'lines_count' => 0,
                    'reason' => mb_substr($reason, 0, 500), 'reverses_credit_note_id' => $locked->id, 'created_by' => $admin->id,
                    'issued_at' => $now, 'issued_by' => $admin->id, 'issuer_snapshot' => $locked->issuer_snapshot, 'partner_snapshot' => $locked->partner_snapshot,
                    'invoice_balance_after' => $invoice->balance, 'pdf_status' => CreditNote::PDF_PENDING,
                ]);
                ActivityLog::record('billing.credit_note_reversed', $reversal, ['number' => $number, 'reverses' => $locked->number, 'invoice' => $invoice->number,
                    'total' => $reversal->total_ttc, 'reason' => mb_substr($reason, 0, 200), 'balance' => $invoice->balance, 'status' => $invoice->status], 'admin');

                return $reversal;
            });
        } catch (UniqueConstraintViolationException) {
            throw new CreditNoteException('already_reversed');
        }
        GenerateCreditNotePdf::dispatch($reversal->id);

        return $reversal;
    }

    /** @throws CreditNoteException */
    private function assertWithinInvoice(Invoice $locked, float $subtotal): void
    {
        $totals = $this->totals($locked, $subtotal);
        $drafts = round((float) CreditNote::where('invoice_id', $locked->id)->draft()->sum('total_ttc'), 2);
        if (round((float) $locked->credited_amount + $drafts + $totals['total_ttc'], 2) > round((float) $locked->total_ttc, 2)) {
            throw new CreditNoteException('exceeds_invoice', ['total' => number_format((float) $locked->total_ttc, 2, ',', ' ')]);
        }
    }

    /** Mêmes règles de TVA et d'arrondi que la facture d'origine (mode et taux figés sur celle-ci). @return array<string, mixed> */
    private function totals(Invoice $invoice, float $subtotal): array
    {
        $mode = (string) $invoice->vat_mode;
        $rate = $invoice->vat_rate === null ? null : (float) $invoice->vat_rate;
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
