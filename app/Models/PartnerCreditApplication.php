<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Consommation d'un crédit partenaire, en ajout seul : imputation sur une facture ouverte (paiement lié de type `credit`)
 * ou remboursement déclaratif (date, mode, référence, note — aucune exécution bancaire par la plateforme).
 */
class PartnerCreditApplication extends Model
{
    public const KIND_APPLY = 'apply';

    public const KIND_REFUND = 'refund';

    public const REFUND_METHODS = ['bank_transfer', 'cash', 'other'];

    protected $guarded = [];

    protected $casts = ['amount' => 'float', 'credit_remaining_after' => 'float', 'refund_on' => 'immutable_date', 'decided_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Credit applications are append-only.'));
        static::deleting(fn () => throw new LogicException('Credit applications cannot be deleted.'));
    }

    public function credit(): BelongsTo
    {
        return $this->belongsTo(PartnerCredit::class, 'partner_credit_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(InvoicePayment::class, 'invoice_payment_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isRefund(): bool
    {
        return $this->kind === self::KIND_REFUND;
    }
}
