<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Période commerciale d'un partenaire : taux de commission appliqué aux réservations créées entre starts_on et ends_on (inclus). */
class PartnerCommissionPeriod extends Model
{
    protected $guarded = [];

    protected $casts = ['commission_pct' => 'float', 'starts_on' => 'immutable_date', 'ends_on' => 'immutable_date'];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function covers(\DateTimeInterface $day): bool
    {
        $d = $day->format('Y-m-d');

        return $this->starts_on->format('Y-m-d') <= $d && ($this->ends_on === null || $this->ends_on->format('Y-m-d') >= $d);
    }

    public function overlaps(string $from, ?string $to): bool
    {
        $mineTo = $this->ends_on?->format('Y-m-d');

        return ($to === null || $this->starts_on->format('Y-m-d') <= $to) && ($mineTo === null || $mineTo >= $from);
    }
}
