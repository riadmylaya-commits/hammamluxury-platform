<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Crédit en faveur d'un partenaire, né d'un avoir dépassant le reste dû ou d'un trop-perçu. Montant et origine figés ; seuls
 * le cumul consommé (imputations / remboursements déclaratifs, chacun une opération liée) et le statut évoluent. Jamais supprimé.
 */
class PartnerCredit extends Model
{
    public const SOURCE_CREDIT_NOTE = 'credit_note';

    public const SOURCE_OVERPAYMENT = 'overpayment';

    public const STATUS_OPEN = 'open';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_REVERSED = 'reversed';

    public const STATUSES = ['open', 'applied', 'refunded', 'reversed'];

    public const MUTABLE = ['applied_amount', 'refunded_amount', 'status', 'closed_by', 'closed_at', 'note', 'updated_at'];

    protected $guarded = [];

    protected $casts = ['amount' => 'float', 'applied_amount' => 'float', 'refunded_amount' => 'float', 'closed_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::updating(function (self $credit) {
            $illegal = array_diff(array_keys($credit->getDirty()), self::MUTABLE);
            if ($illegal !== []) {
                throw new LogicException('Partner credits are append-only: '.implode(', ', $illegal));
            }
        });
        static::deleting(fn () => throw new LogicException('Partner credits cannot be deleted.'));
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }

    public function overpayment(): BelongsTo
    {
        return $this->belongsTo(InvoiceOverpayment::class, 'overpayment_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(PartnerCreditApplication::class)->orderBy('decided_at')->orderBy('id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function remaining(): float
    {
        return round(max(0, $this->amount - $this->applied_amount - $this->refunded_amount), 2);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_OPEN);
    }

    public function sourceLabel(): string
    {
        return $this->source === self::SOURCE_CREDIT_NOTE
            ? (string) ($this->creditNote?->number ?? __('billing.credit_note'))
            : (string) ($this->overpayment?->invoice?->number ?? __('billing.overpayment'));
    }
}
