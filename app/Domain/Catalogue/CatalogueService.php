<?php

namespace App\Domain\Catalogue;

use App\Domain\Booking\QuoteBuilder;
use App\Domain\Policy\CancellationPolicy;
use App\Models\Category;
use App\Models\Spa;
use App\Models\SpaHour;
use App\Models\Treatment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Catalogue public : établissements réservables et prestations actives, sans aucune donnée interne (juridique, coordonnées directes). */
class CatalogueService
{
    public function bookableSpas(): Builder
    {
        return Spa::query()->where('status', 'published')->whereHas('partner', fn ($q) => $q->where('status', 'approved'));
    }

    public const SORTS = ['recommended', 'price_asc', 'price_desc'];

    /**
     * Recherche par ville/zone/nom (insensible à la casse, accents non normalisés).
     * Une date ne garde que les établissements ouverts ce jour-là et non fermés par un blocage couvrant toute la journée.
     */
    public function search(?string $q, ?string $category = null, ?CarbonImmutable $date = null, string $sort = 'recommended'): Builder
    {
        $query = $this->bookableSpas()->with(['photos', 'categories', 'amenities'])->withCount(['treatments' => fn ($t) => $t->where('status', 'active')]);
        if ($q = trim((string) $q)) {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower($q)).'%';
            $query->where(fn ($w) => $w->whereRaw('LOWER(city) LIKE ?', [$like])->orWhereRaw('LOWER(area) LIKE ?', [$like])->orWhereRaw('LOWER(name) LIKE ?', [$like]));
        }
        if ($category) {
            $query->whereHas('treatments', fn ($t) => $t->where('status', 'active')->where('category', $category));
        }
        if ($date) {
            $this->openOn($query, $date);
        }

        return match ($sort) {
            'price_asc' => $query->orderByRaw('price_from IS NULL')->orderBy('price_from')->orderBy('name'),
            'price_desc' => $query->orderByDesc('price_from')->orderBy('name'),
            default => $query->orderByDesc('rating')->orderBy('name'),
        };
    }

    private function openOn(Builder $query, CarbonImmutable $date): void
    {
        $weekday = $date->dayOfWeekIso - 1;
        $dayStart = $date->startOfDay();
        $dayEnd = $date->addDay()->startOfDay();

        $query->whereHas('hours', fn ($h) => $h->where('weekday', $weekday))
            ->whereDoesntHave('blocks', fn ($b) => $b->where('scope', 'spa')->where('start_at', '<=', $dayStart)->where('end_at', '>=', $dayEnd));
    }

    /** Date de recherche valide (Y-m-d, aujourd'hui ou plus tard), sinon null. */
    public static function parseDate(?string $value): ?CarbonImmutable
    {
        if (! $value || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        try {
            $d = CarbonImmutable::createFromFormat('Y-m-d', $value, config('hl.timezone'))->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        return $d->lt(CarbonImmutable::today(config('hl.timezone'))) ? null : $d;
    }

    /** @return Collection<int, array> prestations actives d'un spa avec formules, étapes et extras */
    public function treatments(Spa $spa): Collection
    {
        return $spa->treatments()->where('status', 'active')->with(['steps.resourceType', 'extras' => fn ($q) => $q->where('status', 'active')->orderBy('id')])->orderBy('sort_order')->get()->map(fn (Treatment $t) => $this->treatment($t));
    }

    public function treatment(Treatment $t): array
    {
        $steps = $t->steps->sortBy('position')->values();

        return [
            'id' => $t->id,
            'slug' => $t->slug,
            'name' => $t->tr('name'),
            'category' => $t->category,
            'description' => $t->tr('description'),
            'duration_min' => $t->computedDuration(),
            'duration_label' => Presentation::duration($t->computedDuration()),
            'is_package' => $steps->count() > 1,
            'steps' => $steps->map(fn ($s) => ['type' => $s->displayLabel(), 'duration_min' => $s->duration_min])->all(),
            'components' => $steps->count() > 1 ? $steps->map(fn ($s) => $s->displayLabel().' '.$s->duration_min.' '.__('ui.min'))->implode(' + ') : null,
            'included' => collect($t->included ?? [])->map(fn ($k) => __('ui.included.'.$k))->values()->all(),
            'badge' => $t->featured_badge ? __('ui.badge.'.$t->featured_badge) : null,
            'party_min' => $t->party_min,
            'party_max' => $t->party_max,
            'price_solo' => $t->price_solo !== null ? (float) $t->price_solo : null,
            'price_couple' => $t->price_couple !== null ? (float) $t->price_couple : null,
            'price_group' => $t->price_group !== null ? (float) $t->price_group : null,
            'price_from' => (float) QuoteBuilder::treatmentPrice($t, 1)['unit'],
            'cancellation_hours' => $t->cancellationHours(),
            'nr_discount_pct' => $t->hasNonRefundable() ? (int) $t->nr_discount_pct : null,
            'nr_price_from' => $t->hasNonRefundable() ? CancellationPolicy::discounted((float) QuoteBuilder::treatmentPrice($t, 1)['unit'], (int) $t->nr_discount_pct) : null,
            'nr_price_couple' => $t->hasNonRefundable() && $t->price_couple !== null ? CancellationPolicy::discounted((float) $t->price_couple, (int) $t->nr_discount_pct) : null,
            'extras' => $t->extras->map(fn ($e) => [
                'id' => $e->id, 'name' => $e->tr('name'), 'description' => $e->tr('description'),
                'price' => (float) $e->price, 'extra_min' => $e->extra_min, 'per_person' => (bool) $e->per_person, 'max_qty' => $e->max_qty,
            ])->values()->all(),
        ];
    }

    /** Fiche publique : coordonnées directes exclues tant que la réservation n'est pas confirmée. */
    public function spaCard(Spa $spa): array
    {
        return [
            'id' => $spa->id,
            'slug' => $spa->slug,
            'name' => $spa->name,
            'description' => $spa->tr('description'),
            'city' => $spa->city,
            'area' => $spa->area,
            'lat' => $spa->lat,
            'lng' => $spa->lng,
            'category' => $spa->category,
            'category_label' => Category::labelsBySlug()[$spa->category] ?? $spa->category,
            'rating' => $spa->rating !== null ? (float) $spa->rating : null,
            'reviews_count' => (int) $spa->reviews_count,
            'price_from' => $spa->price_from !== null ? (float) $spa->price_from : null,
            'photos' => $spa->photos->map(fn ($p) => ['url' => $p->url(), 'caption' => $p->tr('caption')])->values()->all(),
            'features' => $spa->featureLabels(),
            'practical' => Presentation::practicalRows($spa->practical_info),
            'hours' => $spa->hours->groupBy('weekday')->map(fn ($rows) => $rows->map(fn ($h) => SpaHour::toHhmm($h->opens_min).'–'.SpaHour::toHhmm($h->closes_min))->values()->all())->all(),
        ];
    }
}
