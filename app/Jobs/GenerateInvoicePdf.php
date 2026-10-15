<?php

namespace App\Jobs;

use App\Domain\Billing\BillingNotifier;
use App\Domain\Billing\InvoicePdf;
use App\Models\ActivityLog;
use App\Models\Invoice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Étape 2 de l'émission : PDF puis notification « facture émise » au partenaire. Rejouable (hl:billing-pdf-retry) ; en cas
 * d'échec, la facture reste émise (aucune seconde facture), `pdf_status = failed` et l'administrateur est alerté.
 */
class GenerateInvoicePdf implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $invoiceId) {}

    public function handle(InvoicePdf $pdf, BillingNotifier $notifier): void
    {
        $invoice = Invoice::with('partner')->find($this->invoiceId);
        if ($invoice === null || ! $invoice->isIssued()) {
            return;
        }
        if ($invoice->pdf_status === Invoice::PDF_READY && $pdf->verify($invoice)) {
            $this->notifyPartner($invoice, $notifier);

            return;
        }
        $invoice->update(['pdf_attempts' => $invoice->pdf_attempts + 1]);
        try {
            $path = $pdf->generate($invoice);
            $invoice->update(['pdf_status' => Invoice::PDF_READY, 'pdf_path' => $path, 'pdf_sha256' => $pdf->sha256($path), 'pdf_error' => null]);
            ActivityLog::record('billing.invoice_pdf_ready', $invoice, ['number' => $invoice->number, 'sha256' => $invoice->pdf_sha256], 'system');
            $this->notifyPartner($invoice->fresh(['partner']), $notifier);
        } catch (Throwable $e) {
            $invoice->update(['pdf_status' => Invoice::PDF_FAILED, 'pdf_path' => null, 'pdf_sha256' => null, 'pdf_error' => mb_substr($e->getMessage(), 0, 500)]);
            ActivityLog::record('billing.invoice_pdf_failed', $invoice, ['number' => $invoice->number, 'attempt' => $invoice->pdf_attempts, 'error' => mb_substr($e->getMessage(), 0, 200)], 'system');
            $alert = (string) config('hl.alert_email');
            if ($alert !== '') {
                $notifier->dispatch($invoice, 'invoice_pdf_failed', 'admin', $alert, ['number' => $invoice->number, 'error' => mb_substr($e->getMessage(), 0, 200)], 'attempt-'.$invoice->pdf_attempts);
            }
        }
    }

    /** Clé unique par facture : la notification n'est envoyée qu'une fois même si le job est rejoué. */
    private function notifyPartner(Invoice $invoice, BillingNotifier $notifier): void
    {
        $recipient = (string) ($invoice->partner_snapshot['billing_email'] ?? ($invoice->partner->billing_email ?: $invoice->partner->user?->email));
        if ($recipient !== '') {
            $notifier->dispatch($invoice, 'invoice_issued', 'partner', $recipient, ['number' => $invoice->number, 'period' => $invoice->period,
                'total' => $invoice->total_ttc, 'due' => $invoice->due_at?->format('d/m/Y')]);
        }
    }
}
