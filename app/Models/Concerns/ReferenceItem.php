<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/** Ligne de référentiel (ville, expérience, équipement) : slug stable + libellé FR/EN, gérée en admin. */
trait ReferenceItem
{
    use Translatable;

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true)->orderBy('sort_order')->orderBy('name_fr');
    }

    public function label(): string
    {
        return (string) $this->tr('name');
    }

    /** @return array<int, string> id => libellé traduit (formulaires) */
    public static function options(): array
    {
        return static::query()->active()->get()->mapWithKeys(fn (self $r) => [$r->id => $r->label()])->all();
    }

    /** @return array<string, string> slug => libellé traduit */
    public static function labelsBySlug(): array
    {
        return static::query()->orderBy('sort_order')->get()->mapWithKeys(fn (self $r) => [$r->slug => $r->label()])->all();
    }
}
