<?php

namespace App\Domain\Billing;

use App\Models\InvoicePayment;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Reçu d'espèces numéroté : même discipline que la facture (disque privé, empreinte SHA-256 vérifiée, jamais régénéré une fois prêt). */
class ReceiptPdf
{
    public const DISK = InvoicePdf::DISK;

    public function path(InvoicePayment $payment): string
    {
        return 'receipts/'.$payment->paid_on->format('Y').'/'.$payment->receipt_number.'.pdf';
    }

    public function render(InvoicePayment $payment): string
    {
        $payment->loadMissing(['invoice.partner', 'confirmedBy']);

        return Pdf::loadView('pdf.receipt', ['payment' => $payment, 'invoice' => $payment->invoice])->setPaper('a5')->output();
    }

    /** @throws PaymentException */
    public function generate(InvoicePayment $payment): string
    {
        if (! $payment->isCash() || $payment->receipt_number === null || $payment->isReversal()) {
            throw new PaymentException('receipt_not_applicable');
        }
        if ($payment->receipt_status === InvoicePayment::RECEIPT_READY && $this->verify($payment)) {
            return $payment->receipt_path;
        }
        $bytes = $this->render($payment);
        if (! str_starts_with($bytes, '%PDF')) {
            throw new PaymentException('receipt_render_failed');
        }
        $path = $this->path($payment);
        $disk = Storage::disk(self::DISK);
        if ($disk->put($path, $bytes, ['visibility' => 'private']) !== true
            || ! $disk->fileExists($path)
            || ! hash_equals(hash('sha256', $bytes), $this->sha256($path))) {
            throw new PaymentException('receipt_write_failed');
        }

        return $path;
    }

    public function verify(InvoicePayment $payment): bool
    {
        $disk = Storage::disk(self::DISK);
        try {
            if (! $payment->receipt_path || ! $payment->receipt_sha256 || ! $disk->fileExists($payment->receipt_path) || $disk->size($payment->receipt_path) === 0) {
                return false;
            }

            return hash_equals($payment->receipt_sha256, $this->sha256($payment->receipt_path));
        } catch (Throwable) {
            return false;
        }
    }

    public function sha256(string $path): string
    {
        return hash('sha256', (string) Storage::disk(self::DISK)->get($path));
    }
}
