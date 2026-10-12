<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Booking extends Model
{
    public const STATUSES = ['waiting', 'confirmed', 'declined', 'cancelled', 'expired', 'completed', 'no_show', 'partner_no_show'];

    public const SOURCES = ['online', 'partner'];

    public const CHANNELS = ['phone', 'whatsapp', 'walk_in', 'email', 'other'];

    public const PAYMENT_STATUSES = ['on_site', 'paid', 'partial', 'refunded'];

    /** Statuts qui ne consomment plus de capacité. */
    public const INACTIVE_STATUSES = ['declined', 'cancelled', 'expired', 'no_show', 'partner_no_show'];

    /** Fenêtre (heures après la fin prévue) pendant laquelle le partenaire peut déclarer lui-même un no-show. */
    public const NO_SHOW_WINDOW_HOURS = 4;

    protected $guarded = [];

    protected $hidden = ['intent_token_hash'];

    protected $casts = [
        'intent_expires_at' => 'immutable_datetime',
        'quote' => 'array',
        'start_at' => 'immutable_datetime', 'end_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime',
        'expiration_notified_at' => 'datetime', 'confirmed_at' => 'datetime', 'cancelled_at' => 'datetime',
        'no_show_at' => 'datetime', 'contact_revealed_at' => 'datetime',
        'total' => 'float', 'commissionable_amount' => 'float', 'commission_pct' => 'float', 'commission_amount' => 'float',
        'no_show_fee' => 'float', 'no_show_fee_waived' => 'bool',
    ];

    protected static function booted(): void
    {
        static::creating(function (Booking $b) {
            $b->reference ??= self::newReference();
            $b->manage_token ??= Str::random(48);
        });
    }

    public static function newReference(): string
    {
        do {
            $ref = 'HL'.strtoupper(Str::random(6));
        } while (self::where('reference', $ref)->exists());

        return $ref;
    }

    public function spa(): BelongsTo
    {
        return $this->belongsTo(Spa::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(BookingParticipant::class)->orderBy('participant_no');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(BookingEvent::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(BookingNote::class)->latest();
    }

    public function cancellationRequests(): HasMany
    {
        return $this->hasMany(CancellationRequest::class)->latest();
    }

    public function pendingCancellationRequest(): HasOne
    {
        return $this->hasOne(CancellationRequest::class)->where('status', 'pending')->latestOfMany();
    }

    public function decline(): HasOne
    {
        return $this->hasOne(BookingDecline::class)->latestOfMany();
    }

    public function declines(): HasMany
    {
        return $this->hasMany(BookingDecline::class);
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(ClientIncident::class)->latest();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(BookingMessage::class)->oldest();
    }

    public function unreadMessagesFor(string $reader): int
    {
        return $this->messages()->unreadFor($reader)->count();
    }

    /** Le partenaire peut demander l'annulation d'une réservation confirmée et à venir, une seule demande en cours à la fois. */
    public function canRequestCancellation(): bool
    {
        return $this->isConfirmed() && $this->start_at->isFuture() && ! $this->pendingCancellationRequest()->exists();
    }

    /** Règle commune à l'API, Livewire et aux services ; une prestation clôturée n'est jamais annulable. */
    public function canCancel(string $actor = 'client'): bool
    {
        return in_array($actor, ['client', 'admin'], true)
            && in_array($this->status, ['waiting', 'confirmed'], true)
            && $this->start_at !== null
            && $this->start_at->gt(now());
    }

    /**
     * Le partenaire peut déclarer un no-show entre l'heure du rendez-vous et NO_SHOW_WINDOW_HOURS après la fin prévue ;
     * au-delà, seule l'administration peut corriger.
     */
    public function partnerNoShowWindowOpen(): bool
    {
        return $this->isConfirmed() && $this->start_at->lte(now()) && now()->lte($this->end_at->copy()->addHours(self::NO_SHOW_WINDOW_HOURS));
    }

    /** Le partenaire peut signaler un comportement du client dans la même fenêtre que le no-show : du rendez-vous à la fin + 4 h. */
    public function canReportGuest(): bool
    {
        return in_array($this->status, ['confirmed', 'completed', 'no_show'], true)
            && $this->start_at->lte(now())
            && now()->lte($this->end_at->copy()->addHours(self::NO_SHOW_WINDOW_HOURS));
    }

    public function partnerWindowClosed(): bool
    {
        return now()->gt($this->end_at->copy()->addHours(self::NO_SHOW_WINDOW_HOURS));
    }

    /** Coordonnées (téléphone / WhatsApp) accessibles au partenaire uniquement une fois la réservation confirmée. */
    public function contactVisibleToPartner(): bool
    {
        return in_array($this->status, ['confirmed', 'completed', 'no_show', 'partner_no_show'], true);
    }

    /** Empreinte stable du client (téléphone puis e-mail) pour rapprocher les signalements entre établissements. */
    public function clientKey(): string
    {
        return hash('sha256', mb_strtolower(trim($this->phone ?: $this->email ?: (string) $this->id)));
    }

    /** Montant sur lequel porte la commission (total si non figé). */
    public function commissionableAmount(): float
    {
        return (float) ($this->commissionable_amount ?? $this->total);
    }

    /** Estimation au devis : elle ne constitue ni une dette acquise ni un encaissement. */
    public function netForPartner(): float
    {
        return round((float) $this->total - (float) $this->commission_amount, 2);
    }

    /** Rien n'est dû : réservation non honorée par l'établissement, no-show sans frais, ou réservation refusée/annulée/expirée. */
    public function nothingDue(): bool
    {
        return $this->status === 'partner_no_show'
            || ($this->status === 'no_show' && ($this->no_show_fee_waived || (float) $this->no_show_fee <= 0))
            || in_array($this->status, ['declined', 'cancelled', 'expired'], true)
            || $this->payment_status === 'refunded';
    }

    /** Montant acquis par réalisation ou frais d'absence ; distinct d'un paiement déclaré sur place. */
    public function amountDue(): float
    {
        if ($this->nothingDue()) {
            return 0.0;
        }

        return match ($this->status) {
            'completed' => round((float) $this->total, 2),
            'no_show' => round(max(0.0, min((float) $this->total, (float) $this->no_show_fee)), 2),
            default => 0.0,
        };
    }

    /** Confirmation = prévision ; clôture/frais = dette ; paid/partial = déclaration, pas preuve d'encaissement. */
    public function hasAccruedAmount(): bool
    {
        return $this->amountDue() > 0;
    }

    /** Commission acquise, au taux figé du devis ; un remboursement intégral l'annule. */
    public function commissionDue(): float
    {
        $due = $this->amountDue();
        if ($due <= 0 || (float) $this->total <= 0 || $this->source === 'partner') {
            return 0.0;
        }

        // Une éventuelle réduction des frais d'absence réduit la commission dans la même proportion.
        return round((float) $this->commission_amount * $due / (float) $this->total, 2);
    }

    /** Net partenaire effectivement dû. */
    public function netDue(): float
    {
        return round($this->amountDue() - $this->commissionDue(), 2);
    }

    public function isActive(): bool
    {
        return ! in_array($this->status, self::INACTIVE_STATUSES, true);
    }

    public function isWaiting(): bool
    {
        return $this->status === 'waiting';
    }

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }

    /** Confirmée dès la création (réservation instantanée ou saisie partenaire), sans décision ultérieure de l'établissement. */
    public function instantConfirmed(): bool
    {
        return $this->confirmed_at !== null && ! $this->events()->where('type', 'confirmed')->exists();
    }

    public function customerName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function log(string $type, ?string $actor = null, array $payload = []): BookingEvent
    {
        return $this->events()->create(['type' => $type, 'actor' => $actor, 'payload' => $payload ?: null]);
    }
}
