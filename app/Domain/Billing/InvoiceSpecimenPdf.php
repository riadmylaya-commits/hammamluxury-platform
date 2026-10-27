<?php

namespace App\Domain\Billing;

use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;

/**
 * Spécimen de facture mensuelle de commission (présentation uniquement) : généré à la volée depuis des
 * données fictives, sans numéro officiel, sans TVA, sans aucune écriture en base. Indépendant de BillingGate.
 */
class InvoiceSpecimenPdf
{
    public function __construct(private BillingSettings $settings) {}

    public function data(): array
    {
        $now = CarbonImmutable::now(config('hl.timezone'));
        $period = $now->subMonthNoOverflow()->startOfMonth();
        $base = 18400.00;
        $rate = (float) config('hl.default_commission_pct', 15);
        $commission = round($base * $rate / 100, 2);

        return [
            'issuer' => array_map(fn ($v) => filled($v) ? $v : null, $this->settings->issuer()),
            'partner' => [
                'company_name' => 'Hammam Démo SARL',
                'legal_form' => 'SARL',
                'billing_address' => '12, rue de la Kasbah',
                'billing_city' => '40000 Marrakech',
                'ice' => '000000000000000',
                'tax_id' => '00000000',
                'registry_number' => '000000',
                'spa_name' => 'Hammam Démo — Marrakech',
            ],
            'number' => 'SPÉCIMEN-'.$period->format('Y-m'),
            'issued_at' => $now,
            'period' => $period,
            'bookings_count' => 12,
            'base' => $base,
            'rate' => $rate,
            'commission' => $commission,
            'currency' => 'MAD',
        ];
    }

    public function html(): string
    {
        return view('pdf.invoice-specimen', $this->data())->render();
    }

    public function render(): string
    {
        return Pdf::loadHTML($this->html())->setPaper('a4')->output();
    }

    public function filename(): string
    {
        return 'specimen-facture-commission-hammamluxury.pdf';
    }
}
