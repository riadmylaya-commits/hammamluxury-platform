<?php

namespace App\Domain\Billing;

use App\Models\ActivityLog;
use App\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Passage quotidien (08:00 Casablanca) sur les factures émises non soldées : statut « en retard » après l'échéance, rappels
 * partenaire J−5, J, J+7, J+15 (alerte administrateur à J+15). Chaque rappel est une notification traçable à clé unique
 * (facture + étape) et n'est envoyé qu'une fois ; une facture soldée ne reçoit plus rien.
 */
class InvoiceReminders
{
    /** @var array<string, int> étape => décalage en jours par rapport à l'échéance */
    public const STEPS = ['reminder_before_due' => -5, 'reminder_due' => 0, 'reminder_late_1' => 7, 'reminder_late_2' => 15];

    public function __construct(private readonly BillingNotifier $notifier, private readonly PaymentRecorder $recorder) {}

    /** @return array{overdue: int, reminders: int} */
    public function run(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now(config('hl.timezone'));
        $today = $now->startOfDay();
        $overdue = 0;
        $reminders = 0;
        foreach (Invoice::openForPayment()->whereNotNull('due_at')->with('partner')->orderBy('id')->cursor() as $invoice) {
            DB::transaction(function () use ($invoice, $now, $today, &$overdue, &$reminders) {
                $locked = Invoice::whereKey($invoice->id)->lockForUpdate()->with('partner')->first();
                if ($locked === null || ! $locked->isOpenForPayment() || $locked->balance <= 0) {
                    return;
                }
                $status = $this->recorder->statusFor($locked, (float) $locked->balance, (float) $locked->paid_amount, $now);
                if ($status !== $locked->status) {
                    $locked->update(['status' => $status]);
                    if ($status === Invoice::STATUS_OVERDUE) {
                        $overdue++;
                        ActivityLog::record('billing.invoice_overdue', $locked, ['number' => $locked->number, 'balance' => $locked->balance, 'due_at' => $locked->due_at->toDateString()], 'system');
                    }
                }
                $sent = $locked->reminders_sent ?? [];
                foreach (self::STEPS as $step => $offset) {
                    if (isset($sent[$step]) || $today->lt($locked->due_at->addDays($offset))) {
                        continue;
                    }
                    if ($this->send($locked, $step)) {
                        $reminders++;
                    }
                    $sent[$step] = $now->toDateTimeString();
                }
                if ($sent !== ($locked->reminders_sent ?? [])) {
                    $locked->update(['reminders_sent' => $sent]);
                }
            });
        }

        return ['overdue' => $overdue, 'reminders' => $reminders];
    }

    private function send(Invoice $invoice, string $step): bool
    {
        $recipient = (string) ($invoice->partner_snapshot['billing_email'] ?? ($invoice->partner?->billing_email ?: $invoice->partner?->user?->email));
        $payload = ['number' => $invoice->number, 'period' => $invoice->period, 'total' => $invoice->total_ttc, 'balance' => $invoice->balance,
            'due' => $invoice->due_at->format('d/m/Y'), 'partner' => $invoice->partner_snapshot['company_name'] ?? $invoice->partner?->company_name];
        $n = $recipient !== '' ? $this->notifier->dispatch($invoice, $step, 'partner', $recipient, $payload) : null;
        $alert = (string) config('hl.alert_email');
        if ($step === 'reminder_late_2' && $alert !== '') {
            $this->notifier->dispatch($invoice, 'invoice_late', 'admin', $alert, $payload);
        }
        ActivityLog::record('billing.reminder_sent', $invoice, ['number' => $invoice->number, 'step' => $step, 'balance' => $invoice->balance, 'notified' => $n !== null], 'system');

        return $n !== null;
    }
}
