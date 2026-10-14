<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

/**
 * Facture mensuelle d'une entité juridique partenaire. Brouillon recalculable ; une fois émise (numéro, date réelle, lignes,
 * montants, identités figées), seules les colonnes de suivi (PDF, statut de règlement, notes) peuvent encore changer.
 */
class Invoice extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ISSUED = 'issued';

    public const STATUSES = ['draft', 'issued', 'partially_paid', 'paid', 'overdue', 'cancelled'];

    public const PDF_PENDING = 'pending';

    public const PDF_READY = 'ready';

    public const PDF_FAILED = 'failed';

    /** Colonnes modifiables après émission (suivi, jamais le contenu facturé). */
    public const MUTABLE_AFTER_ISSUE = ['status', 'pdf_status', 'pdf_path', 'pdf_sha256', 'pdf_attempts', 'pdf_error', 'notes', 'updated_at'];

    protected $guarded = [];

    protected $casts = [
        'subtotal_ht' => 'float', 'vat_rate' => 'float', 'vat_amount' => 'float', 'total_ttc' => 'float',
        'drafted_at' => 'immutable_datetime', 'issued_at' => 'immutable_datetime', 'due_at' => 'immutable_date',
        'issuer_snapshot' => 'array', 'partner_snapshot' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $invoice) {
            if ($invoice->getOriginal('status') === self::STATUS_DRAFT) {
                return;
            }
            $illegal = array_diff(array_keys($invoice->getDirty()), self::MUTABLE_AFTER_ISSUE);
            if ($illegal !== []) {
                throw new LogicException('Issued invoices are immutable: '.implode(', ', $illegal));
            }
        });
        static::deleting(fn (self $invoice) => $invoice->isIssued() ? throw new LogicException('Issued invoices cannot be deleted.') : null);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('position');
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
        return $this->status !== self::STATUS_DRAFT && $this->issued_at !== null;
    }

    public function scopeDraft(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_DRAFT);
    }

    public function scopeIssued(Builder $q): Builder
    {
        return $q->where('status', '!=', self::STATUS_DRAFT)->whereNotNull('issued_at');
    }

    /** Sous-totaux par établissement, dans l'ordre des lignes. @return array<int, array{spa: ?Spa, lines: list<InvoiceLine>, total: float}> */
    public function sections(): array
    {
        $out = [];
        foreach ($this->lines as $line) {
            $key = (int) ($line->spa_id ?? 0);
            $out[$key] ??= ['spa' => $line->spa, 'lines' => [], 'total' => 0.0];
            $out[$key]['lines'][] = $line;
            $out[$key]['total'] = round($out[$key]['total'] + $line->amount, 2);
        }

        return $out;
    }

    public function label(): string
    {
        return $this->number ?? __('billing.invoice_draft_label', ['period' => $this->period]);
    }
}
