<?php

namespace App\Domain\Billing;

use App\Jobs\GenerateReceiptPdf;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\InvoiceOverpayment;
use App\Models\InvoicePayment;
use App\Models\InvoiceSequence;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Paiements reçus sur une facture émise, en ajout seul. Chaque opération (paiement ou contre-passation) est enregistrée
 * sous verrou de la facture, avec recalcul du montant payé, du solde et du statut dans la même transaction. Une clé
 * d'idempotence propre à l'opération empêche le double enregistrement d'une même saisie sans interdire deux paiements
 * légitimes de même montant. Les espèces sont plafonnées strictement (aucune dérogation) et donnent lieu à un reçu numéroté ;
 * un excédent est signalé à l'administrateur, jamais imputé automatiquement. Le grand livre n'est jamais touché.
 */
class PaymentRecorder
{
    public function __construct(private readonly BillingSettings $settings, private readonly BillingNotifier $notifier) {}

    /**
     * @param  array{amount: float|int|string, paid_on: string, method: string, reference?: ?string, note?: ?string, idempotency_key: string}  $data
     *
     * @throws PaymentException
     */
    public function record(Invoice $invoice, User $admin, array $data, ?CarbonImmutable $now = null): InvoicePayment
    {
        if (! $admin->isAdmin()) {
            throw new PaymentException('forbidden');
        }
        $key = trim((string) ($data['idempotency_key'] ?? ''));
        if ($key === '' || mb_strlen($key) > 64) {
            throw new PaymentException('idempotency_key_invalid');
        }
        if ($existing = InvoicePayment::where('idempotency_key', $key)->first()) {
            return $existing;
        }
        $amount = round((float) ($data['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new PaymentException('amount_not_positive');
        }
        $method = (string) ($data['method'] ?? '');
        if (! in_array($method, InvoicePayment::METHODS, true)) {
            throw new PaymentException('method_invalid');
        }
        $tz = config('hl.timezone');
        $now ??= CarbonImmutable::now($tz);
        $paidOn = CarbonImmutable::createFromFormat('Y-m-d', (string) ($data['paid_on'] ?? ''), $tz);
        if ($paidOn === false || $paidOn->startOfDay()->gt($now->startOfDay())) {
            throw new PaymentException('paid_on_invalid');
        }

        try {
            $payment = DB::transaction(function () use ($invoice, $admin, $amount, $method, $paidOn, $data, $key, $now) {
                $locked = Invoice::whereKey($invoice->id)->lockForUpdate()->first();
                if ($locked === null || ! $locked->isIssued()) {
                    throw new PaymentException('invoice_not_issued');
                }
                if (! $locked->isOpenForPayment()) {
                    throw new PaymentException('invoice_closed', ['status' => __('billing.invoice_status_'.$locked->status)]);
                }
                if ($method === InvoicePayment::METHOD_CASH) {
                    $this->assertCashWithinLimit($locked, $amount);
                }
                $paidBefore = round((float) $locked->paid_amount, 2);
                $paidAfter = round($paidBefore + $amount, 2);
                $excess = round(max(0, $paidAfter - (float) $locked->total_ttc), 2);
                $balanceAfter = round(max(0, (float) $locked->total_ttc - $paidAfter), 2);

                $cash = $method === InvoicePayment::METHOD_CASH;
                $payment = InvoicePayment::create([
                    'invoice_id' => $locked->id, 'partner_id' => $locked->partner_id, 'amount' => $amount, 'currency' => $locked->currency,
                    'paid_on' => $paidOn->toDateString(), 'method' => $method,
                    'reference' => $this->clean($data['reference'] ?? null, 120), 'note' => $this->clean($data['note'] ?? null, 1000),
                    'idempotency_key' => $key, 'confirmed_by' => $admin->id, 'confirmed_at' => $now, 'balance_after' => $balanceAfter,
                    'receipt_number' => $cash ? sprintf('HL-R-%d-%05d', $paidOn->year, InvoiceSequence::next($paidOn->year, 'receipt')) : null,
                    'receipt_status' => $cash ? InvoicePayment::RECEIPT_PENDING : null,
                ]);
                $this->apply($locked, $paidAfter, $now);
                if ($excess > 0) {
                    InvoiceOverpayment::create(['invoice_id' => $locked->id, 'payment_id' => $payment->id, 'partner_id' => $locked->partner_id,
                        'amount' => $excess, 'currency' => $locked->currency, 'status' => InvoiceOverpayment::STATUS_OPEN]);
                }
                ActivityLog::record('billing.payment_recorded', $payment, ['number' => $locked->number, 'amount' => $amount, 'method' => $method,
                    'paid_on' => $paidOn->toDateString(), 'receipt' => $payment->receipt_number, 'balance' => $balanceAfter, 'status' => $locked->status,
                    'overpayment' => $excess > 0 ? $excess : null], 'admin');
                $this->notify($locked->fresh(['partner']), $payment, $excess);

                return $payment;
            });
        } catch (UniqueConstraintViolationException) {
            $existing = InvoicePayment::where('idempotency_key', $key)->first();
            if ($existing === null) {
                throw new PaymentException('conflict');
            }

            return $existing;
        }
        if ($payment->isCash()) {
            GenerateReceiptPdf::dispatch($payment->id);
        }

        return $payment;
    }

    /**
     * Contre-passation d'un paiement saisi par erreur : écriture négative liée, une seule fois par paiement, motif obligatoire.
     *
     * @throws PaymentException
     */
    public function reverse(InvoicePayment $payment, User $admin, string $reason, string $idempotencyKey, ?CarbonImmutable $now = null): InvoicePayment
    {
        if (! $admin->isAdmin()) {
            throw new PaymentException('forbidden');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new PaymentException('reason_required');
        }
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 64) {
            throw new PaymentException('idempotency_key_invalid');
        }
        if ($existing = InvoicePayment::where('idempotency_key', $idempotencyKey)->first()) {
            return $existing;
        }
        $now ??= CarbonImmutable::now(config('hl.timezone'));

        try {
            return DB::transaction(function () use ($payment, $admin, $reason, $idempotencyKey, $now) {
                $locked = Invoice::whereKey($payment->invoice_id)->lockForUpdate()->first();
                $original = InvoicePayment::whereKey($payment->id)->lockForUpdate()->first();
                if ($locked === null || $original === null) {
                    throw new PaymentException('not_found');
                }
                if ($original->isReversal() || $original->amount <= 0) {
                    throw new PaymentException('cannot_reverse_reversal');
                }
                if ($original->reversedBy()->exists()) {
                    throw new PaymentException('already_reversed');
                }
                $paidAfter = round((float) $locked->paid_amount - (float) $original->amount, 2);
                $balanceAfter = round(max(0, (float) $locked->total_ttc - $paidAfter), 2);
                $reversal = InvoicePayment::create([
                    'invoice_id' => $locked->id, 'partner_id' => $locked->partner_id, 'amount' => -(float) $original->amount, 'currency' => $locked->currency,
                    'paid_on' => $now->toDateString(), 'method' => $original->method, 'reference' => $original->reference,
                    'note' => null, 'idempotency_key' => $idempotencyKey, 'reverses_payment_id' => $original->id,
                    'reversal_reason' => mb_substr($reason, 0, 500), 'confirmed_by' => $admin->id, 'confirmed_at' => $now, 'balance_after' => $balanceAfter,
                ]);
                $this->apply($locked, $paidAfter, $now);
                $open = InvoiceOverpayment::where('payment_id', $original->id)->open()->first();
                if ($open !== null) {
                    $open->update(['status' => 'reversed', 'decided_by' => $admin->id, 'decided_at' => $now, 'decision_note' => mb_substr($reason, 0, 500)]);
                }
                ActivityLog::record('billing.payment_reversed', $reversal, ['number' => $locked->number, 'amount' => -(float) $original->amount,
                    'reverses' => $original->id, 'receipt' => $original->receipt_number, 'reason' => mb_substr($reason, 0, 200), 'balance' => $balanceAfter,
                    'status' => $locked->status], 'admin');

                return $reversal;
            });
        } catch (UniqueConstraintViolationException) {
            $existing = InvoicePayment::where('idempotency_key', $idempotencyKey)->first();
            if ($existing === null) {
                throw new PaymentException('already_reversed');
            }

            return $existing;
        }
    }

    /** Statut cohérent avec le solde et l'échéance ; à appeler sous verrou (paiement) ou par le passage quotidien (retard). */
    public function apply(Invoice $locked, float $paidAfter, CarbonImmutable $now): void
    {
        $paidAfter = round($paidAfter, 2);
        $balance = round(max(0, (float) $locked->total_ttc - $paidAfter), 2);
        $locked->update([
            'paid_amount' => $paidAfter,
            'balance' => $balance,
            'status' => $this->statusFor($locked, $balance, $paidAfter, $now),
            'paid_at' => $balance <= 0 ? ($locked->paid_at ?? $now) : null,
        ]);
    }

    public function statusFor(Invoice $invoice, float $balance, float $paid, CarbonImmutable $now): string
    {
        if ($balance <= 0) {
            return Invoice::STATUS_PAID;
        }
        if ($invoice->due_at !== null && $invoice->due_at->lt($now->startOfDay())) {
            return Invoice::STATUS_OVERDUE;
        }

        return $paid > 0 ? Invoice::STATUS_PARTIALLY_PAID : Invoice::STATUS_ISSUED;
    }

    /** Cumul des espèces déjà reçues sur la facture (contre-passations déduites). */
    public function cashReceived(Invoice $invoice): float
    {
        return round((float) InvoicePayment::where('invoice_id', $invoice->id)->cash()->sum('amount'), 2);
    }

    public function cashLimit(): float
    {
        return (float) $this->settings->get('cash_limit');
    }

    /** @throws PaymentException */
    private function assertCashWithinLimit(Invoice $locked, float $amount): void
    {
        $limit = $this->cashLimit();
        $total = round($this->cashReceived($locked) + $amount, 2);
        if ($limit > 0 && $total > $limit) {
            throw new PaymentException('cash_limit_exceeded', ['limit' => number_format($limit, 2, ',', ' '), 'total' => number_format($total, 2, ',', ' ')]);
        }
    }

    /** Virement : notification immédiate ; espèces : la notification part avec le reçu, une fois celui-ci prêt (job). */
    private function notify(Invoice $invoice, InvoicePayment $payment, float $excess): void
    {
        $alert = (string) config('hl.alert_email');
        if ($excess > 0 && $alert !== '') {
            $this->notifier->dispatch($invoice, 'overpayment_detected', 'admin', $alert, ['number' => $invoice->number, 'total' => $excess,
                'amount' => $payment->amount, 'partner' => $invoice->partner_snapshot['company_name'] ?? $invoice->partner?->company_name], 'payment-'.$payment->id);
        }
        if (! $payment->isCash()) {
            $this->notifyPartner($invoice, $payment);
        }
    }

    /** Notification partenaire « paiement enregistré » (partiel ou soldé), une seule fois par paiement. */
    public function notifyPartner(Invoice $invoice, InvoicePayment $payment): void
    {
        $recipient = (string) ($invoice->partner_snapshot['billing_email'] ?? ($invoice->partner?->billing_email ?: $invoice->partner?->user?->email));
        if ($recipient === '') {
            return;
        }
        $event = $invoice->isPaid() ? 'payment_received' : 'payment_partial';
        $this->notifier->dispatch($invoice, $event, 'partner', $recipient, ['number' => $invoice->number, 'period' => $invoice->period,
            'amount' => $payment->amount, 'total' => $invoice->total_ttc, 'balance' => $invoice->balance, 'paid_on' => $payment->paid_on->format('d/m/Y'),
            'method' => $payment->method, 'receipt' => $payment->receipt_number], 'payment-'.$payment->id);
    }

    private function clean(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
