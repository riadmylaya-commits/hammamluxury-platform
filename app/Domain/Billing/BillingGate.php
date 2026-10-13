<?php

namespace App\Domain\Billing;

use App\Models\LedgerEntry;
use App\Models\Partner;
use Carbon\CarbonInterface;

/**
 * Verrous serveur de la facturation. Toute émission réelle (lots suivants) devra passer par assertCanIssue() :
 * un bouton masqué dans l'interface ne suffit jamais.
 */
class BillingGate
{
    public function __construct(private readonly BillingSettings $settings) {}

    /** @return list<string> codes de blocage globaux (vide = émission possible côté plateforme) */
    public function globalBlockers(): array
    {
        $out = [];
        if (! $this->settings->enabled()) {
            $out[] = 'billing_disabled';
        }
        if ($this->settings->startPeriod() === null) {
            $out[] = 'start_period_missing';
        }
        if ($this->settings->get('vat_mode') === 'pending_validation') {
            $out[] = 'vat_mode_pending';
        }
        if ($this->settings->get('vat_mode') === 'ht_plus_vat' && $this->settings->get('vat_rate') === null) {
            $out[] = 'vat_rate_missing';
        }
        if ($this->settings->get('accountant_validated_at') === null) {
            $out[] = 'accountant_not_validated';
        }
        foreach ($this->settings->issuer() as $field => $value) {
            if ($value === null || trim((string) $value) === '') {
                $out[] = 'issuer_'.$field.'_missing';
            }
        }

        return $out;
    }

    /** @return list<string> codes de blocage propres au partenaire (données légales de facturation) */
    public function partnerBlockers(Partner $partner): array
    {
        $out = [];
        foreach (['company_name', 'legal_form', 'tax_id', 'registry_number', 'billing_address', 'billing_city'] as $field) {
            if (trim((string) $partner->{$field}) === '') {
                $out[] = 'partner_'.$field.'_missing';
            }
        }
        if ($partner->ice_not_applicable) {
            if (trim((string) $partner->ice_not_applicable_reason) === '') {
                $out[] = 'partner_ice_reason_missing';
            }
        } elseif (! preg_match('/^\d{15}$/', (string) $partner->ice)) {
            $out[] = 'partner_ice_missing';
        }
        $email = (string) ($partner->billing_email ?: $partner->user?->email);
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $out[] = 'partner_billing_email_missing';
        }

        return $out;
    }

    public function partnerReady(Partner $partner): bool
    {
        return $this->partnerBlockers($partner) === [];
    }

    public function canIssue(?Partner $partner = null): bool
    {
        return $this->globalBlockers() === [] && ($partner === null || $this->partnerReady($partner));
    }

    /** @throws BillingLockedException */
    public function assertCanIssue(?Partner $partner = null): void
    {
        $blockers = $this->globalBlockers();
        if ($partner !== null) {
            $blockers = [...$blockers, ...$this->partnerBlockers($partner)];
        }
        if ($blockers !== []) {
            throw new BillingLockedException($blockers);
        }
    }

    /** Une écriture est antérieure au démarrage si elle précède le 1er jour du premier mois facturable. */
    public function isBeforeStart(CarbonInterface $createdAt): bool
    {
        $start = $this->settings->startPeriod();

        return $start !== null && $createdAt->lt($start);
    }

    /** Écriture facturable : qualifiée « billable » explicitement, jamais « legacy ». Null = non qualifiée = non facturable. */
    public function isBillable(LedgerEntry $entry): bool
    {
        return $entry->billing_scope === LedgerEntry::SCOPE_BILLABLE;
    }
}
