<?php

namespace App\Jobs;

use App\Domain\Billing\BillingNotifier;
use App\Domain\Billing\CreditNotePdf;
use App\Models\ActivityLog;
use App\Models\CreditNote;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Étape 2 de l'émission d'un avoir : PDF puis notification « avoir émis » au partenaire. Rejouable (hl:billing-credit-note-retry) ;
 * en cas d'échec l'avoir reste émis (jamais de second avoir), `pdf_status = failed`, chemin et empreinte effacés, administrateur alerté.
 */
class GenerateCreditNotePdf implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $creditNoteId) {}

    public function handle(CreditNotePdf $pdf, BillingNotifier $notifier): void
    {
        $note = CreditNote::with(['partner', 'invoice'])->find($this->creditNoteId);
        if ($note === null || ! $note->isIssued()) {
            return;
        }
        if ($note->pdf_status === CreditNote::PDF_READY && $pdf->verify($note)) {
            $this->notifyPartner($note, $notifier);

            return;
        }
        $note->update(['pdf_attempts' => $note->pdf_attempts + 1]);
        try {
            $path = $pdf->generate($note);
            $note->update(['pdf_status' => CreditNote::PDF_READY, 'pdf_path' => $path, 'pdf_sha256' => $pdf->sha256($path), 'pdf_error' => null]);
            ActivityLog::record('billing.credit_note_pdf_ready', $note, ['number' => $note->number, 'sha256' => $note->pdf_sha256], 'system');
            $this->notifyPartner($note->fresh(['partner', 'invoice']), $notifier);
        } catch (Throwable $e) {
            $note->update(['pdf_status' => CreditNote::PDF_FAILED, 'pdf_path' => null, 'pdf_sha256' => null, 'pdf_error' => mb_substr($e->getMessage(), 0, 500)]);
            ActivityLog::record('billing.credit_note_pdf_failed', $note, ['number' => $note->number, 'attempt' => $note->pdf_attempts, 'error' => mb_substr($e->getMessage(), 0, 200)], 'system');
            $alert = (string) config('hl.alert_email');
            if ($alert !== '') {
                $notifier->dispatch($note, 'credit_note_pdf_failed', 'admin', $alert, ['number' => $note->number, 'invoice' => $note->invoice?->number,
                    'error' => mb_substr($e->getMessage(), 0, 200)], 'attempt-'.$note->pdf_attempts);
            }
        }
    }

    /** Une seule notification par avoir, même si le job est rejoué. */
    private function notifyPartner(CreditNote $note, BillingNotifier $notifier): void
    {
        $recipient = (string) ($note->partner_snapshot['billing_email'] ?? ($note->partner?->billing_email ?: $note->partner?->user?->email));
        if ($recipient !== '') {
            $notifier->dispatch($note, 'credit_note_issued', 'partner', $recipient, ['number' => $note->number, 'invoice' => $note->invoice?->number,
                'total' => $note->total_ttc, 'balance' => $note->invoice_balance_after, 'reverses' => $note->reverses?->number]);
        }
    }
}
