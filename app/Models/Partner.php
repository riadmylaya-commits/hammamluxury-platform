<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Partner extends Model
{
    protected $guarded = [];

    protected $casts = ['commission_pct' => 'float', 'ice_not_applicable' => 'bool'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function spas(): HasMany
    {
        return $this->hasMany(Spa::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function commissionPeriods(): HasMany
    {
        return $this->hasMany(PartnerCommissionPeriod::class)->orderBy('starts_on');
    }

    /** Période commerciale couvrant ce jour (heure du Maroc), s'il y en a une. */
    public function commissionPeriodOn(?\DateTimeInterface $day = null): ?PartnerCommissionPeriod
    {
        $day ??= CarbonImmutable::now(config('hl.timezone'));

        return $this->commissionPeriods->first(fn (PartnerCommissionPeriod $p) => $p->covers($day));
    }

    /** Taux applicable ce jour : période commerciale en cours, sinon taux propre au partenaire, sinon taux standard. */
    public function commissionPct(?\DateTimeInterface $day = null): float
    {
        return $this->commissionPeriodOn($day)?->commission_pct ?? $this->commission_pct ?? (float) config('hl.default_commission_pct');
    }

    /** Partenaire commercial (au moins une fiche sous accord) ou simple référencement gratuit (toutes ses fiches référencées). */
    public function isCommercial(): bool
    {
        return $this->spas->contains(fn (Spa $s) => ! $s->isReferenced());
    }
}
