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
    /**
     * Verrou de code : l'émission réelle dépend des lots suivants (brouillons, factures, envoi, paiements).
     * Ne passe à true que par une PR revue, dans le dernier lot nécessaire ; aucun réglage ni variable d'environnement ne le lève.
     */
    public const ISSUANCE_LOTS_DELIVERED = false;

    public function __construct(private readonly BillingSettings $settings) {}

    /** Blocages que l'interface ne peut pas lever : lots livrés, recette complète, autorisation explicite de la propriétaire. */
    public function releaseBlockers(): array
    {
        $out = [];
        if (! $this->issuanceLotsDelivered()) {
            $out[] = 'lots_not_delivered';
        }
        if (trim((string) config('hl.billing.acceptance_ref')) === '') {
            $out[] = 'acceptance_not_recorded';
        }
        if (trim((string) config('hl.billing.owner_authorization_ref')) === '') {
            $out[] = 'owner_authorization_missing';
        }

        return $out;
    }

    protected function issuanceLotsDelivered(): bool
    {
        return self::ISSUANCE_LOTS_DELIVERED;
    }

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
        if ($this->settings->startPeriod() !== null && LedgerEntry::whereNull('billing_scope')->exists()) {
            $out[] = 'ledger_unqualified_entries';
        }

        return [...$out, ...$this->releaseBlockers()];
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

    /**
     * Périmètre d'une écriture. Une correction de commission (contre-écriture, ajustement) suit toujours la commission initiale
     * de sa chaîne : une correction tardive d'une commission legacy reste legacy, celle d'une commission facturable reste
     * facturable. Les autres écritures suivent leur date d'enregistrement (date d'acquisition de la commission).
     */
    public function scopeFor(LedgerEntry $entry): ?string
    {
        if ($this->settings->startPeriod() === null) {
            return null;
        }
        $root = $entry;
        $seen = [];
        while ($root->corrects_entry_id !== null && ! isset($seen[$root->id])) {
            $seen[$root->id] = true;
            $parent = LedgerEntry::find($root->corrects_entry_id);
            if ($parent === null) {
                break;
            }
            if ($parent->billing_scope !== null) {
                return $parent->billing_scope;
            }
            $root = $parent;
        }

        return $this->isBeforeStart($root->created_at) ? LedgerEntry::SCOPE_LEGACY : LedgerEntry::SCOPE_BILLABLE;
    }

    /** Écriture facturable : qualifiée « billable » explicitement, jamais « legacy ». Null = non qualifiée = non facturable. */
    public function isBillable(LedgerEntry $entry): bool
    {
        return $entry->billing_scope === LedgerEntry::SCOPE_BILLABLE;
    }
}
