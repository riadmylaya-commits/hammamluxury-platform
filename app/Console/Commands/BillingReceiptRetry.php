<?php

namespace App\Console\Commands;

use App\Jobs\GenerateReceiptPdf;
use App\Models\InvoicePayment;
use Illuminate\Console\Command;

/** Rejoue la génération des reçus d'espèces en échec ou en attente ; jamais de nouveau numéro ni de nouveau paiement. */
class BillingReceiptRetry extends Command
{
    protected $signature = 'hl:billing-receipt-retry {--max=5 : Nombre maximal de tentatives}';

    protected $description = 'Relance la génération des reçus d\'espèces en échec ou en attente';

    public function handle(): int
    {
        $stuck = InvoicePayment::cash()->whereNotNull('receipt_number')->where('receipt_attempts', '<', (int) $this->option('max'))
            ->where(fn ($q) => $q->where('receipt_status', InvoicePayment::RECEIPT_FAILED)
                ->orWhere(fn ($q) => $q->where('receipt_status', InvoicePayment::RECEIPT_PENDING)->where('updated_at', '<', now()->subMinutes(15))))
            ->get();
        foreach ($stuck as $payment) {
            GenerateReceiptPdf::dispatch($payment->id);
            $this->line($payment->receipt_number.' · tentative '.($payment->receipt_attempts + 1));
        }
        $this->info($stuck->count().' relance(s).');

        return self::SUCCESS;
    }
}
