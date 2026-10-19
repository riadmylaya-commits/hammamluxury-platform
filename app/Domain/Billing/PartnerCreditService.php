<?php

namespace App\Domain\Billing;

use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\InvoiceOverpayment;
use App\Models\InvoicePayment;
use App\Models\PartnerCredit;
use App\Models\PartnerCreditApplication;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Crédits partenaires (issus d'un avoir dépassant le reste dû ou d'un trop-perçu) : résolution manuelle et tracée par
 * l'Admin, en ajout seul. Imputer = paiement lié de type `credit` sur une facture ouverte du même partenaire (sans jamais
 * dépasser le reste du crédit ni le solde de la facture, donc sans nouveau trop-perçu). Rembourser = enregistrement
 * déclaratif (date, mode, référence, note) ; la plateforme n'exécute aucun mouvement bancaire. Un crédit ne se consomme
 * qu'une fois : verrou de ligne + clé d'idempotence unique par opération.
 */
class PartnerCreditService
{
    public function __construct(private readonly InvoiceBalance $balance, private readonly BillingNotifier $notifier) {}

    /** Ouvre (une seule fois) le crédit partenaire correspondant à un trop-perçu signalé par F3. @throws CreditNoteException */
    public function fromOverpayment(InvoiceOverpayment $overpayment, User $admin, ?CarbonImmutable $now = null): PartnerCredit
    {
        if (! $admin->isAdmin()) {
            throw new CreditNoteException('forbidden');
        }
        $now ??= CarbonImmutable::now(config('hl.timezone'));

        return DB::transaction(function () use ($overpayment, $admin, $now) {
            $locked = InvoiceOverpayment::whereKey($overpayment->id)->lockForUpdate()->first();
            if ($locked === null) {
                throw new CreditNoteException('not_found');
            }
            if ($locked->partner_credit_id !== null) {
                return PartnerCredit::findOrFail($locked->partner_credit_id);
            }
            if ($locked->status !== InvoiceOverpayment::STATUS_OPEN) {
                throw new CreditNoteException('overpayment_not_open');
            }
            $credit = PartnerCredit::create(['partner_id' => $locked->partner_id, 'source' => PartnerCredit::SOURCE_OVERPAYMENT, 'overpayment_id' => $locked->id,
                'amount' => round((float) $locked->amount, 2), 'currency' => $locked->currency, 'status' => PartnerCredit::STATUS_OPEN, 'created_by' => $admin->id]);
            $locked->update(['partner_credit_id' => $credit->id, 'decided_by' => $admin->id, 'decided_at' => $now]);
            ActivityLog::record('billing.credit_opened', $credit, ['source' => 'overpayment', 'invoice' => $locked->invoice?->number, 'amount' => $credit->amount], 'admin');

            return $credit;
        });
    }

    /**
     * Imputation (partielle ou totale) d'un crédit sur une facture ouverte du même partenaire.
     *
     * @throws CreditNoteException
     */
    public function apply(PartnerCredit $credit, Invoice $target, User $admin, float $amount, string $idempotencyKey, ?CarbonImmutable $now = null): PartnerCreditApplication
    {
        if (! $admin->isAdmin()) {
            throw new CreditNoteException('forbidden');
        }
        $key = $this->key($idempotencyKey);
        if ($existing = PartnerCreditApplication::where('idempotency_key', $key)->first()) {
            return $existing;
        }
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new CreditNoteException('amount_not_positive');
        }
        $now ??= CarbonImmutable::now(config('hl.timezone'));

        try {
            $application = DB::transaction(function () use ($credit, $target, $admin, $amount, $key, $now) {
                $lockedCredit = PartnerCredit::whereKey($credit->id)->lockForUpdate()->first();
                $invoice = Invoice::whereKey($target->id)->lockForUpdate()->first();
                if ($lockedCredit === null || $invoice === null) {
                    throw new CreditNoteException('not_found');
                }
                if (! $lockedCredit->isOpen()) {
                    throw new CreditNoteException('credit_closed');
                }
                if ($invoice->partner_id !== $lockedCredit->partner_id) {
                    throw new CreditNoteException('other_partner');
                }
                if (! $invoice->isOpenForPayment()) {
                    throw new CreditNoteException('invoice_closed', ['status' => __('billing.invoice_status_'.$invoice->status)]);
                }
                if ($amount > $lockedCredit->remaining()) {
                    throw new CreditNoteException('exceeds_credit', ['remaining' => number_format($lockedCredit->remaining(), 2, ',', ' ')]);
                }
                if ($amount > round((float) $invoice->balance, 2)) {
                    throw new CreditNoteException('exceeds_balance', ['balance' => number_format((float) $invoice->balance, 2, ',', ' ')]);
                }
                $paidAfter = round((float) $invoice->paid_amount + $amount, 2);
                $balanceAfter = round(max(0, (float) $invoice->total_ttc - $paidAfter - (float) $invoice->credited_amount), 2);
                $payment = InvoicePayment::create([
                    'invoice_id' => $invoice->id, 'partner_id' => $invoice->partner_id, 'amount' => $amount, 'currency' => $invoice->currency,
                    'paid_on' => $now->toDateString(), 'method' => InvoicePayment::METHOD_CREDIT, 'reference' => $lockedCredit->sourceLabel(),
                    'note' => null, 'idempotency_key' => 'credit-'.$key, 'confirmed_by' => $admin->id, 'confirmed_at' => $now, 'balance_after' => $balanceAfter,
                ]);
                $this->balance->apply($invoice, $paidAfter, (float) $invoice->credited_amount, $now);
                $application = $this->record($lockedCredit, PartnerCreditApplication::KIND_APPLY, $amount, $key, $admin, $now,
                    ['invoice_id' => $invoice->id, 'invoice_payment_id' => $payment->id]);
                $this->close($lockedCredit, $admin, $now, null);
                ActivityLog::record('billing.credit_applied', $application, ['credit' => $lockedCredit->id, 'source' => $lockedCredit->sourceLabel(), 'invoice' => $invoice->number,
                    'amount' => $amount, 'remaining' => $lockedCredit->remaining(), 'balance' => $invoice->balance, 'status' => $invoice->status], 'admin');
                $this->notifyPartner($invoice, 'credit_applied', ['number' => $invoice->number, 'amount' => $amount, 'balance' => $invoice->balance,
                    'source' => $lockedCredit->sourceLabel(), 'remaining' => $lockedCredit->remaining()], 'credit-'.$application->id);

                return $application;
            });
        } catch (UniqueConstraintViolationException) {
            $existing = PartnerCreditApplication::where('idempotency_key', $key)->first();
            if ($existing === null) {
                throw new CreditNoteException('conflict');
            }

            return $existing;
        }

        return $application;
    }

    /**
     * Remboursement déclaratif (partiel ou total) : l'Admin enregistre un remboursement réellement effectué hors plateforme.
     *
     * @param  array{amount: float|int|string, refund_on: string, method: string, reference?: ?string, note?: ?string}  $data
     *
     * @throws CreditNoteException
     */
    public function refund(PartnerCredit $credit, User $admin, array $data, string $idempotencyKey, ?CarbonImmutable $now = null): PartnerCreditApplication
    {
        if (! $admin->isAdmin()) {
            throw new CreditNoteException('forbidden');
        }
        $key = $this->key($idempotencyKey);
        if ($existing = PartnerCreditApplication::where('idempotency_key', $key)->first()) {
            return $existing;
        }
        $amount = round((float) ($data['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new CreditNoteException('amount_not_positive');
        }
        $method = (string) ($data['method'] ?? '');
        if (! in_array($method, PartnerCreditApplication::REFUND_METHODS, true)) {
            throw new CreditNoteException('method_invalid');
        }
        $reference = trim((string) ($data['reference'] ?? ''));
        if ($reference === '') {
            throw new CreditNoteException('reference_required');
        }
        $tz = config('hl.timezone');
        $now ??= CarbonImmutable::now($tz);
        $refundOn = CarbonImmutable::createFromFormat('Y-m-d', (string) ($data['refund_on'] ?? ''), $tz);
        if ($refundOn === false || $refundOn->startOfDay()->gt($now->startOfDay())) {
            throw new CreditNoteException('refund_on_invalid');
        }

        try {
            return DB::transaction(function () use ($credit, $admin, $amount, $method, $reference, $refundOn, $data, $key, $now) {
                $locked = PartnerCredit::whereKey($credit->id)->lockForUpdate()->first();
                if ($locked === null) {
                    throw new CreditNoteException('not_found');
                }
                if (! $locked->isOpen()) {
                    throw new CreditNoteException('credit_closed');
                }
                if ($amount > $locked->remaining()) {
                    throw new CreditNoteException('exceeds_credit', ['remaining' => number_format($locked->remaining(), 2, ',', ' ')]);
                }
                $note = trim((string) ($data['note'] ?? ''));
                $application = $this->record($locked, PartnerCreditApplication::KIND_REFUND, $amount, $key, $admin, $now, ['refund_on' => $refundOn->toDateString(),
                    'refund_method' => $method, 'refund_reference' => mb_substr($reference, 0, 120), 'note' => $note === '' ? null : mb_substr($note, 0, 1000)]);
                $this->close($locked, $admin, $now, $note === '' ? null : mb_substr($note, 0, 500));
                ActivityLog::record('billing.credit_refunded', $application, ['credit' => $locked->id, 'source' => $locked->sourceLabel(), 'amount' => $amount,
                    'refund_on' => $refundOn->toDateString(), 'method' => $method, 'reference' => mb_substr($reference, 0, 120), 'remaining' => $locked->remaining()], 'admin');
                $invoice = $locked->source === PartnerCredit::SOURCE_CREDIT_NOTE ? $locked->creditNote?->invoice : $locked->overpayment?->invoice;
                if ($invoice !== null) {
                    $this->notifyPartner($invoice, 'credit_refunded', ['number' => $invoice->number, 'amount' => $amount, 'refund_on' => $refundOn->format('d/m/Y'),
                        'method' => $method, 'reference' => mb_substr($reference, 0, 120), 'source' => $locked->sourceLabel(), 'remaining' => $locked->remaining()], 'refund-'.$application->id);
                }

                return $application;
            });
        } catch (UniqueConstraintViolationException) {
            $existing = PartnerCreditApplication::where('idempotency_key', $key)->first();
            if ($existing === null) {
                throw new CreditNoteException('conflict');
            }

            return $existing;
        }
    }

    /** @param  array<string, mixed>  $extra */
    private function record(PartnerCredit $locked, string $kind, float $amount, string $key, User $admin, CarbonImmutable $now, array $extra): PartnerCreditApplication
    {
        $column = $kind === PartnerCreditApplication::KIND_APPLY ? 'applied_amount' : 'refunded_amount';
        $locked->update([$column => round((float) $locked->{$column} + $amount, 2)]);

        return PartnerCreditApplication::create($extra + ['partner_credit_id' => $locked->id, 'kind' => $kind, 'amount' => $amount, 'idempotency_key' => $key,
            'decided_by' => $admin->id, 'decided_at' => $now, 'credit_remaining_after' => $locked->fresh()->remaining()]);
    }

    /** Clôture le crédit (et le trop-perçu d'origine) dès qu'il est entièrement consommé ; statut selon l'usage majoritaire. */
    private function close(PartnerCredit $locked, User $admin, CarbonImmutable $now, ?string $note): void
    {
        $locked->refresh();
        if ($locked->remaining() > 0) {
            return;
        }
        $status = $locked->refunded_amount > $locked->applied_amount ? PartnerCredit::STATUS_REFUNDED : PartnerCredit::STATUS_APPLIED;
        $locked->update(['status' => $status, 'closed_by' => $admin->id, 'closed_at' => $now, 'note' => $note]);
        if ($locked->overpayment_id !== null) {
            InvoiceOverpayment::whereKey($locked->overpayment_id)->first()?->update(['status' => $status, 'decided_by' => $admin->id, 'decided_at' => $now,
                'decision_note' => $note ?? __('billing.credit_status_'.$status)]);
        }
    }

    /** @param  array<string, mixed>  $payload */
    private function notifyPartner(Invoice $invoice, string $event, array $payload, string $suffix): void
    {
        $recipient = (string) ($invoice->partner_snapshot['billing_email'] ?? ($invoice->partner?->billing_email ?: $invoice->partner?->user?->email));
        if ($recipient !== '') {
            $this->notifier->dispatch($invoice, $event, 'partner', $recipient, $payload, $suffix);
        }
    }

    /** @throws CreditNoteException */
    private function key(string $key): string
    {
        $key = trim($key);
        if ($key === '' || mb_strlen($key) > 57) {
            throw new CreditNoteException('idempotency_key_invalid');
        }

        return $key;
    }
}
