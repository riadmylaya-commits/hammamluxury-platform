<?php

namespace App\Console\Commands;

use App\Jobs\GenerateInvoicePdf;
use App\Models\Invoice;
use Illuminate\Console\Command;

/** Rejoue la génération du PDF des factures émises dont le PDF a échoué ou n'a jamais abouti ; jamais de nouvelle facture. */
class BillingPdfRetry extends Command
{
    protected $signature = 'hl:billing-pdf-retry {--max=5 : Nombre maximal de tentatives}';

    protected $description = 'Relance la génération des PDF de factures émises en échec ou en attente';

    public function handle(): int
    {
        $stuck = Invoice::issued()->where('pdf_attempts', '<', (int) $this->option('max'))
            ->where(fn ($q) => $q->where('pdf_status', Invoice::PDF_FAILED)
                ->orWhere(fn ($q) => $q->where('pdf_status', Invoice::PDF_PENDING)->where('updated_at', '<', now()->subMinutes(15))))
            ->get();
        foreach ($stuck as $invoice) {
            GenerateInvoicePdf::dispatch($invoice->id);
            $this->line($invoice->number.' · tentative '.($invoice->pdf_attempts + 1));
        }
        $this->info($stuck->count().' relance(s).');

        return self::SUCCESS;
    }
}
