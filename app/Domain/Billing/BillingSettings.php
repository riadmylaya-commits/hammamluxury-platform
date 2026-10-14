<?php

namespace App\Domain\Billing;

use App\Models\ActivityLog;
use App\Models\BillingSetting;
use App\Models\LedgerEntry;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Paramètres de facturation : valeurs de config/hl.php (verrouillées par défaut) surchargées par la table billing_settings.
 * Toute modification est journalisée avec l'ancienne et la nouvelle valeur.
 */
class BillingSettings
{
    public const KEYS = [
        'enabled', 'start_period', 'vat_mode', 'vat_rate', 'due_days', 'issue_day', 'dispute_until_day', 'accountant_validated_at',
        'issuer.company_name', 'issuer.legal_form', 'issuer.ice', 'issuer.tax_id', 'issuer.registry_number', 'issuer.address', 'issuer.bank_account',
    ];

    public const VAT_MODES = ['pending_validation', 'none', 'ht_plus_vat', 'ttc_included'];

    public const ISSUER_REQUIRED = ['company_name', 'legal_form', 'ice', 'tax_id', 'registry_number', 'address', 'bank_account'];

    /** @var array<string, string|null>|null */
    private ?array $stored = null;

    public function get(string $key): mixed
    {
        if (! in_array($key, self::KEYS, true)) {
            throw new InvalidArgumentException("Unknown billing setting [{$key}].");
        }
        $stored = $this->stored();
        if (array_key_exists($key, $stored)) {
            return $this->cast($key, $stored[$key]);
        }

        return $this->cast($key, config('hl.billing.'.$key));
    }

    public function enabled(): bool
    {
        return (bool) $this->get('enabled');
    }

    public function startPeriod(): ?CarbonImmutable
    {
        $raw = $this->get('start_period');
        if (! is_string($raw) || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $raw)) {
            return null;
        }

        return CarbonImmutable::createFromFormat('Y-m-d H:i:s', $raw.'-01 00:00:00', config('hl.timezone'))->startOfMonth();
    }

    /** @return array<string, mixed> */
    public function issuer(): array
    {
        $out = [];
        foreach (self::ISSUER_REQUIRED as $field) {
            $out[$field] = $this->get('issuer.'.$field);
        }

        return $out;
    }

    /**
     * Enregistre une valeur ; `enabled` ne peut passer à vrai que si BillingGate ne signale plus aucun blocage, y compris les
     * verrous hors interface (lots, recette, autorisation). `start_period` est figée dès qu'une écriture est qualifiée.
     */
    public function set(string $key, mixed $value, ?string $panel = null): void
    {
        if (! in_array($key, self::KEYS, true)) {
            throw new InvalidArgumentException("Unknown billing setting [{$key}].");
        }
        $value = $this->normalize($key, $value);
        $old = $this->get($key);
        if ($old === $this->cast($key, $value)) {
            return;
        }
        if ($key === 'start_period' && LedgerEntry::whereNotNull('billing_scope')->exists()) {
            // Déplacer la date après qualification laisserait des écritures dans le mauvais périmètre (perte ou double facturation).
            throw new BillingLockedException(['start_period_frozen']);
        }
        if ($key === 'enabled' && $value === '1') {
            $blockers = array_values(array_diff(app()->make(BillingGate::class, ['settings' => $this])->globalBlockers(), ['billing_disabled', 'ledger_unqualified_entries']));
            if ($blockers !== []) {
                throw new BillingLockedException($blockers);
            }
        }

        BillingSetting::updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => auth()->id()]);
        $this->stored = null;
        ActivityLog::record('billing.setting_changed', null, ['key' => $key, 'old' => $old, 'new' => $this->cast($key, $value)], $panel);
    }

    /** @return array<string, string|null> */
    private function stored(): array
    {
        return $this->stored ??= BillingSetting::query()->pluck('value', 'key')->all();
    }

    private function normalize(string $key, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($key === 'enabled') {
            return $value ? '1' : '0';
        }
        if ($key === 'vat_mode' && ! in_array($value, self::VAT_MODES, true)) {
            throw new InvalidArgumentException("Invalid vat_mode [{$value}].");
        }
        if ($key === 'start_period' && ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $value)) {
            throw new InvalidArgumentException('start_period must be formatted YYYY-MM.');
        }
        if ($key === 'vat_rate' && (! is_numeric($value) || (float) $value < 0 || (float) $value > 100)) {
            throw new InvalidArgumentException('vat_rate must be between 0 and 100.');
        }

        return (string) $value;
    }

    private function cast(string $key, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return $key === 'enabled' ? false : null;
        }

        return match ($key) {
            'enabled' => in_array($value, [true, 1, '1', 'true'], true),
            'vat_rate' => (float) $value,
            'due_days', 'issue_day', 'dispute_until_day' => (int) $value,
            default => (string) $value,
        };
    }
}
