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

    public const PAYMENT_STATUSES = ['on_site', 'paid', 'partial', 'refunded'];

    /** Statuts qui ne consomment plus de capacité. */
    public const INACTIVE_STATUSES = ['declined', 'cancelled', 'expired', 'no_show', 'partner_no_show'];

    /** Fenêtre (heures après la fin prévue) pendant laquelle le partenaire peut déclarer lui-même un no-show. */
    public const NO_SHOW_WINDOW_HOURS = 4;

    protected $guarded = [];

    protected $casts = [
        'quote' => 'array',
        'start_at' => 'datetime', 'end_at' => 'datetime', 'expires_at' => 'datetime',
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

    /**
     * Le partenaire peut déclarer un no-show entre l'heure du rendez-vous et NO_SHOW_WINDOW_HOURS après la fin prévue ;
     * au-delà, seule l'administration peut corriger.
     */
    public function partnerNoShowWindowOpen(): bool
    {
        return $this->isConfirmed() && $this->start_at->lte(now()) && now()->lte($this->end_at->addHours(self::NO_SHOW_WINDOW_HOURS));
    }

    /** Le partenaire peut signaler un comportement du client à partir de l'heure du rendez-vous. */
    public function canReportGuest(): bool
    {
        return in_array($this->status, ['confirmed', 'completed', 'no_show'], true) && $this->start_at->lte(now());
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

    /** Ce qui revient au partenaire une fois la commission HammamLuxury déduite. */
    public function netForPartner(): float
    {
        return round((float) $this->total - (float) $this->commission_amount, 2);
    }

    /** Rien n'est dû : réservation non honorée par l'établissement, no-show sans frais, ou réservation refusée/annulée/expirée. */
    public function nothingDue(): bool
    {
        return $this->status === 'partner_no_show'
            || ($this->status === 'no_show' && $this->no_show_fee_waived)
            || in_array($this->status, ['declined', 'cancelled', 'expired'], true);
    }

    /** Commission effectivement due (0 lorsque rien n'est facturé). */
    public function commissionDue(): float
    {
        return $this->nothingDue() ? 0.0 : (float) $this->commission_amount;
    }

    /** Net partenaire effectivement dû. */
    public function netDue(): float
    {
        return $this->nothingDue() ? 0.0 : $this->netForPartner();
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

    public function customerName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function log(string $type, ?string $actor = null, array $payload = []): BookingEvent
    {
        return $this->events()->create(['type' => $type, 'actor' => $actor, 'payload' => $payload ?: null]);
    }
}
