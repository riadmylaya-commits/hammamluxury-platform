<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

/**
 * Avoir sur une facture émise. Brouillon recalculable ; une fois émis (numéro, date réelle, lignes, montants, identités
 * figées), seules les colonnes de suivi du PDF peuvent encore changer. Une erreur se corrige par un avoir d'annulation lié
 * (`reverses_credit_note_id`, total négatif), jamais par modification ni suppression.
 */
class CreditNote extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ISSUED = 'issued';

    public const PDF_PENDING = 'pending';

    public const PDF_READY = 'ready';

    public const PDF_FAILED = 'failed';

    public const MUTABLE_AFTER_ISSUE = ['pdf_status', 'pdf_path', 'pdf_sha256', 'pdf_attempts', 'pdf_error', 'updated_at'];

    protected $guarded = [];

    protected $casts = [
        'subtotal_ht' => 'float', 'vat_rate' => 'float', 'vat_amount' => 'float', 'total_ttc' => 'float', 'invoice_balance_after' => 'float',
        'issued_at' => 'immutable_datetime', 'issuer_snapshot' => 'array', 'partner_snapshot' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $note) {
            if ($note->getOriginal('status') === self::STATUS_DRAFT) {
                return;
            }
            $illegal = array_diff(array_keys($note->getDirty()), self::MUTABLE_AFTER_ISSUE);
            if ($illegal !== []) {
                throw new LogicException('Issued credit notes are immutable: '.implode(', ', $illegal));
            }
        });
        static::deleting(fn (self $note) => $note->isIssued() ? throw new LogicException('Issued credit notes cannot be deleted.') : null);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function dispute(): BelongsTo
    {
        return $this->belongsTo(CommissionDispute::class, 'dispute_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CreditNoteLine::class)->orderBy('position');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_credit_note_id');
    }

    public function reversedBy(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_credit_note_id');
    }

    public function partnerCredit(): HasOne
    {
        return $this->hasOne(PartnerCredit::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function notifications(): MorphMany
    {
        return $this->morphMany(BillingNotification::class, 'notifiable');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isIssued(): bool
    {
        return $this->status === self::STATUS_ISSUED && $this->issued_at !== null;
    }

    public function isReversal(): bool
    {
        return $this->reverses_credit_note_id !== null;
    }

    public function scopeIssued(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_ISSUED)->whereNotNull('issued_at');
    }

    public function scopeDraft(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_DRAFT);
    }

    public function label(): string
    {
        return $this->number ?? __('billing.credit_note_draft_label');
    }
}
