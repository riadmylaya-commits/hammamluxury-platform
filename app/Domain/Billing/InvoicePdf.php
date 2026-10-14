<?php

namespace App\Domain\Billing;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * PDF d'une facture émise : rendu dompdf, stockage sur le disque privé `local` (hors public), empreinte SHA-256 enregistrée et
 * revérifiée à chaque téléchargement. Jamais régénéré une fois prêt.
 */
class InvoicePdf
{
    public const DISK = 'local';

    public function path(Invoice $invoice): string
    {
        return 'invoices/'.$invoice->issued_at->format('Y').'/'.$invoice->number.'.pdf';
    }

    public function render(Invoice $invoice): string
    {
        $invoice->loadMissing(['lines.spa', 'partner']);

        return Pdf::loadView('pdf.invoice', ['invoice' => $invoice, 'sections' => $invoice->sections()])->setPaper('a4')->output();
    }

    /** Génère et stocke le PDF ; retourne le chemin. Idempotent : un PDF déjà prêt et intact n'est pas réécrit. */
    public function generate(Invoice $invoice): string
    {
        if (! $invoice->isIssued()) {
            throw new InvoiceException('not_issued');
        }
        if ($invoice->pdf_status === Invoice::PDF_READY && $this->verify($invoice)) {
            return $invoice->pdf_path;
        }
        $bytes = $this->render($invoice);
        $path = $this->path($invoice);
        Storage::disk(self::DISK)->put($path, $bytes, ['visibility' => 'private']);

        return $path;
    }

    /** Le fichier existe et son empreinte correspond à celle enregistrée à la génération. */
    public function verify(Invoice $invoice): bool
    {
        if (! $invoice->pdf_path || ! $invoice->pdf_sha256 || ! Storage::disk(self::DISK)->exists($invoice->pdf_path)) {
            return false;
        }

        return hash_equals($invoice->pdf_sha256, hash('sha256', (string) Storage::disk(self::DISK)->get($invoice->pdf_path)));
    }

    public function sha256(string $path): string
    {
        return hash('sha256', (string) Storage::disk(self::DISK)->get($path));
    }
}
