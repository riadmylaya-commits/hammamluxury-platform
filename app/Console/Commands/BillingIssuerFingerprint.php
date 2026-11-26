<?php

namespace App\Console\Commands;

use App\Domain\Billing\BillingGate;
use Illuminate\Console\Command;

/** Lecture seule : affiche l'empreinte de l'identité de l'émetteur à reporter dans HL_BILLING_ISSUER_FINGERPRINT après autorisation écrite. */
class BillingIssuerFingerprint extends Command
{
    protected $signature = 'hl:billing-issuer-fingerprint';

    protected $description = 'Affiche l\'empreinte de l\'identité de l\'émetteur (lecture seule)';

    public function handle(BillingGate $gate): int
    {
        $this->line($gate->issuerFingerprint());

        return self::SUCCESS;
    }
}
