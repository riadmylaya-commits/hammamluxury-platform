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

    /**
     * Changements de taux à venir (périodes qui commencent ou se terminent), du plus proche au plus lointain.
     *
     * @return list<array{on: CarbonImmutable, from: float, to: float, days: int}>
     */
    public function commissionChanges(?\DateTimeInterface $from = null): array
    {
        $today = CarbonImmutable::parse(($from ?? CarbonImmutable::now(config('hl.timezone')))->format('Y-m-d'));
        $days = [];
        foreach ($this->commissionPeriods as $p) {
            foreach ([$p->starts_on, $p->ends_on?->addDay()] as $d) {
                if ($d !== null && $d->format('Y-m-d') > $today->format('Y-m-d')) {
                    $days[$d->format('Y-m-d')] = true;
                }
            }
        }
        ksort($days);
        $out = [];
        foreach (array_keys($days) as $day) {
            $at = CarbonImmutable::parse($day);
            [$before, $after] = [$this->commissionPct($at->subDay()), $this->commissionPct($at)];
            if ($before !== $after) {
                $out[] = ['on' => $at, 'from' => $before, 'to' => $after, 'days' => (int) $today->diffInDays($at)];
            }
        }

        return $out;
    }

    /** Partenaire commercial (au moins une fiche sous accord) ou simple référencement gratuit (toutes ses fiches référencées). */
    public function isCommercial(): bool
    {
        return $this->spas->contains(fn (Spa $s) => ! $s->isReferenced());
    }
}
