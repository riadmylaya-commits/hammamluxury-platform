<?php

namespace App\Console\Commands;

use App\Jobs\GenerateCreditNotePdf;
use App\Models\CreditNote;
use Illuminate\Console\Command;

/** Rejoue la génération du PDF des avoirs émis dont le PDF a échoué ou n'a jamais abouti ; jamais de nouvel avoir. */
class BillingCreditNotePdfRetry extends Command
{
    protected $signature = 'hl:billing-credit-note-retry {--max=5 : Nombre maximal de tentatives}';

    protected $description = 'Relance la génération des PDF d’avoirs émis en échec ou en attente';

    public function handle(): int
    {
        $stuck = CreditNote::issued()->where('pdf_attempts', '<', (int) $this->option('max'))
            ->where(fn ($q) => $q->where('pdf_status', CreditNote::PDF_FAILED)
                ->orWhere(fn ($q) => $q->where('pdf_status', CreditNote::PDF_PENDING)->where('updated_at', '<', now()->subMinutes(15))))
            ->get();
        foreach ($stuck as $note) {
            GenerateCreditNotePdf::dispatch($note->id);
            $this->line($note->number.' · tentative '.($note->pdf_attempts + 1));
        }
        $this->info($stuck->count().' relance(s).');

        return self::SUCCESS;
    }
}
