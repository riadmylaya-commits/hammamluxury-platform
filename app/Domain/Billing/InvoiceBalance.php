<?php

namespace App\Domain\Billing;

use App\Models\Invoice;
use Carbon\CarbonImmutable;

/**
 * Seul point de recalcul du solde et du statut d'une facture émise, à appeler sous verrou de la facture :
 * solde = total − payé − avoirs (jamais négatif). `cancelled` est réservé à l'annulation totale par avoir d'une facture
 * sans aucun paiement ; un paiement ou un avoir ultérieur ne modifie jamais le contenu facturé, seulement ces colonnes de suivi.
 */
class InvoiceBalance
{
    public function apply(Invoice $locked, float $paid, float $credited, CarbonImmutable $now): void
    {
        $paid = round($paid, 2);
        $credited = round($credited, 2);
        $total = round((float) $locked->total_ttc, 2);
        $balance = round(max(0, $total - $paid - $credited), 2);
        $cancelled = $credited >= $total && $paid <= 0;
        $locked->update([
            'paid_amount' => $paid,
            'credited_amount' => $credited,
            'balance' => $balance,
            'status' => $cancelled ? Invoice::STATUS_CANCELLED : $this->statusFor($locked, $balance, $paid, $now),
            'paid_at' => $balance <= 0 && ! $cancelled ? ($locked->paid_at ?? $now) : null,
            'cancelled_at' => $cancelled ? ($locked->cancelled_at ?? $now) : null,
        ]);
    }

    public function statusFor(Invoice $invoice, float $balance, float $paid, CarbonImmutable $now): string
    {
        if ($balance <= 0) {
            return Invoice::STATUS_PAID;
        }
        if ($invoice->due_at !== null && $invoice->due_at->lt($now->startOfDay())) {
            return Invoice::STATUS_OVERDUE;
        }

        return $paid > 0 ? Invoice::STATUS_PARTIALLY_PAID : Invoice::STATUS_ISSUED;
    }

    /** Excédent (payé + avoirs au-delà du total) ; signalé ou transformé en crédit partenaire, jamais imputé automatiquement. */
    public function excess(Invoice $invoice, float $paid, float $credited): float
    {
        return round(max(0, $paid + $credited - (float) $invoice->total_ttc), 2);
    }
}
