<?php

namespace App\Jobs;

use App\Domain\Billing\BillingNotifier;
use App\Domain\Billing\PaymentRecorder;
use App\Domain\Billing\ReceiptPdf;
use App\Models\ActivityLog;
use App\Models\InvoicePayment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Reçu d'espèces : PDF puis notification « paiement enregistré » au partenaire. Rejouable (hl:billing-receipt-retry) ;
 * en cas d'échec le paiement et son numéro de reçu restent acquis (jamais de second reçu), `receipt_status = failed`,
 * chemin et empreinte effacés, administrateur alerté.
 */
class GenerateReceiptPdf implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $paymentId) {}

    public function handle(ReceiptPdf $pdf, BillingNotifier $notifier, PaymentRecorder $recorder): void
    {
        $payment = InvoicePayment::with(['invoice.partner'])->find($this->paymentId);
        if ($payment === null || ! $payment->isCash() || $payment->receipt_number === null) {
            return;
        }
        if ($payment->receipt_status === InvoicePayment::RECEIPT_READY && $pdf->verify($payment)) {
            $recorder->notifyPartner($payment->invoice, $payment);

            return;
        }
        $payment->update(['receipt_attempts' => $payment->receipt_attempts + 1]);
        try {
            $path = $pdf->generate($payment);
            $payment->update(['receipt_status' => InvoicePayment::RECEIPT_READY, 'receipt_path' => $path, 'receipt_sha256' => $pdf->sha256($path), 'receipt_error' => null]);
            ActivityLog::record('billing.receipt_pdf_ready', $payment, ['receipt' => $payment->receipt_number, 'sha256' => $payment->receipt_sha256], 'system');
            $recorder->notifyPartner($payment->invoice->fresh(['partner']), $payment->fresh());
        } catch (Throwable $e) {
            $payment->update(['receipt_status' => InvoicePayment::RECEIPT_FAILED, 'receipt_path' => null, 'receipt_sha256' => null, 'receipt_error' => mb_substr($e->getMessage(), 0, 500)]);
            ActivityLog::record('billing.receipt_pdf_failed', $payment, ['receipt' => $payment->receipt_number, 'attempt' => $payment->receipt_attempts, 'error' => mb_substr($e->getMessage(), 0, 200)], 'system');
            $alert = (string) config('hl.alert_email');
            if ($alert !== '') {
                $notifier->dispatch($payment->invoice, 'receipt_pdf_failed', 'admin', $alert, ['number' => $payment->invoice->number, 'receipt' => $payment->receipt_number,
                    'error' => mb_substr($e->getMessage(), 0, 200)], 'receipt-'.$payment->id.'-attempt-'.$payment->receipt_attempts);
            }
        }
    }
}
