<?php

namespace App\Domain\Booking;

use App\Models\Allocation;
use App\Models\Block;
use App\Models\Booking;
use App\Models\Extra;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Spa;
use App\Models\Treatment;
use App\Models\TreatmentStep;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Moteur de capacité : unique source de vérité pour la disponibilité.
 *
 * Un « besoin » (need) = un type de ressource occupé sur [start, end[ par `party` personnes,
 * pour un participant donné. Une prestation se décompose en besoins successifs (étapes) ;
 * un extra à durée prolonge le besoin qui se termine le plus tard.
 *
 * Le plan affecte chaque besoin à des ressources physiques : mode `unit` = exclusif
 * (une cabine, un thérapeute), mode `pool` = capacité en personnes (un hammam collectif).
 */
class CapacityEngine
{
    /**
     * Décompose des lignes {treatment, party, extras, participant_no} en besoins.
     *
     * @param  array<int, array{treatment: int|string|Treatment, party?: int, extras?: array, participant_no?: int}>  $items
     * @return array<int, array{type_id:int, type_slug:string, mode:string, start:CarbonImmutable, end:CarbonImmutable, party:int, treatment_id:int, label:string, participant_no:int}>
     */
    public function expandNeeds(Spa $spa, CarbonImmutable $start, array $items): array
    {
        $needs = [];
        foreach ($items as $item) {
            $treatment = $this->resolveTreatment($spa, $item['treatment'] ?? $item['treatment_id'] ?? null);
            if (! $treatment) {
                throw BookingException::make('treatment_missing', 'treatment_not_found');
            }
            if (! $treatment->isActive()) {
                throw BookingException::make('treatment_inactive', 'treatment_unavailable', ['name' => $treatment->tr('name')]);
            }
            $party = max(1, (int) ($item['party'] ?? 1));
            if ($party < $treatment->party_min || $party > $treatment->party_max) {
                throw BookingException::make('party', 'party_range', ['name' => $treatment->tr('name'), 'min' => $treatment->party_min, 'max' => $treatment->party_max]);
            }
            $extraMin = 0;
            if (! empty($item['extras'])) {
                $extras = $treatment->extras->where('status', 'active')->keyBy('id');
                foreach (self::normalizeExtras($extras, $item['extras']) as $eid => $qty) {
                    $extraMin += $qty * (int) $extras[$eid]->extra_min;
                }
            }
            $steps = $treatment->steps->values();
            if ($steps->isEmpty()) {
                throw BookingException::make('no_steps', 'treatment_no_resources', ['name' => $treatment->tr('name')]);
            }
            $offsets = self::stepOffsets($steps);
            $last = 0;
            foreach ($steps as $i => $s) {
                if ($offsets[$i] + $s->duration_min >= $offsets[$last] + $steps[$last]->duration_min) {
                    $last = $i;
                }
            }
            $staffType = $this->staffType($spa);
            foreach ($steps as $i => $s) {
                $type = $s->resourceType;
                $needStart = $start->addMinutes($offsets[$i]);
                $needEnd = $needStart->addMinutes($s->duration_min + ($i === $last ? $extraMin : 0));
                $base = [
                    'start' => $needStart,
                    'end' => $needEnd,
                    'treatment_id' => $treatment->id,
                    'label' => $treatment->tr('name'),
                    'participant_no' => (int) ($item['participant_no'] ?? 0),
                ];
                $needs[] = $base + [
                    'type_id' => $type->id,
                    'type_slug' => $type->slug,
                    'mode' => $type->allocation_mode,
                    'party' => $party,
                ];
                $staff = (int) $s->staff_per_person;
                if ($staff > 0) {
                    // Praticien(s) requis en même temps que la cabine/salle : besoin séparé sur la même fenêtre.
                    $needs[] = $base + [
                        'type_id' => $staffType?->id ?? 0,
                        'type_slug' => $staffType?->slug ?? 'praticien',
                        'mode' => $staffType?->allocation_mode ?? 'unit',
                        'party' => $party * $staff,
                    ];
                }
            }
        }

        return $needs;
    }

    /**
     * Décalage effectif (minutes) de chaque étape : une étape « en parallèle » démarre
     * avec la précédente ; sinon son `offset_min` enregistré.
     *
     * @param  Collection<int, TreatmentStep>  $steps
     * @return array<int, int>
     */
    public static function stepOffsets(Collection $steps): array
    {
        $offsets = [];
        foreach ($steps->values() as $i => $s) {
            $offsets[$i] = $i > 0 && $s->parallel_with_previous ? $offsets[$i - 1] : (int) $s->offset_min;
        }

        return $offsets;
    }

    /** Type de ressource « praticien » de l'établissement (kind = therapist), s'il existe. */
    public function staffType(Spa $spa): ?ResourceType
    {
        return $spa->resourceTypes->firstWhere('kind', 'therapist');
    }

    /** Temps de rotation (minutes) par identifiant de ressource, hérité de son type. */
    private function bufferByResource(Spa $spa): array
    {
        $byType = $spa->resourceTypes->pluck('buffer_min', 'id');

        return $spa->resources->mapWithKeys(fn ($r) => [$r->id => (int) ($byType[$r->resource_type_id] ?? 0)])->all();
    }

    public function resolveTreatment(Spa $spa, int|string|Treatment|null $ref): ?Treatment
    {
        if ($ref instanceof Treatment) {
            return $ref->spa_id === $spa->id ? $ref : null;
        }
        if ($ref === null || $ref === '') {
            return null;
        }
        $query = $spa->treatments()->with(['steps.resourceType', 'extras']);

        return is_numeric($ref) ? $query->find((int) $ref) : $query->where('slug', (string) $ref)->first();
    }

    /**
     * Normalise une sélection d'extras ([id, id], {id: qty} ou [{id, qty}]) en {id => qty} borné par max_qty.
     *
     * @param  Collection<int, Extra>  $extras
     * @return array<int, int>
     */
    public static function normalizeExtras(Collection $extras, mixed $selection): array
    {
        $out = [];
        $selection = (array) $selection;
        $isMap = $selection && array_keys($selection) !== range(0, count($selection) - 1);
        foreach ($selection as $k => $v) {
            if (is_array($v)) {
                $id = (int) ($v['id'] ?? 0);
                $qty = (int) ($v['qty'] ?? 1);
            } elseif ($isMap) {
                $id = (int) $k;
                $qty = (int) $v;
            } else {
                $id = (int) $v;
                $qty = 1;
            }
            if (! $extras->has($id) || $qty <= 0) {
                continue;
            }
            $out[$id] = min((int) $extras[$id]->max_qty, ($out[$id] ?? 0) + $qty);
        }

        return $out;
    }

    /* ------------------------------------------------------------------ Horaires */

    /** L'établissement est-il ouvert sur tout [start, end[ ? Sans horaires renseignés, il est fermé. */
    public function isOpen(Spa $spa, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        if ($spa->hours->isEmpty()) {
            return false;
        }
        $midnight = $start->startOfDay();
        $sMin = $midnight->diffInMinutes($start);
        $eMin = $midnight->diffInMinutes($end);
        foreach ($spa->openingRanges($start->dayOfWeekIso - 1) as [$opens, $closes]) {
            if ($closes <= $opens) {
                $closes += 1440;
            }
            if ($sMin >= $opens && $eMin <= $closes) {
                return true;
            }
        }

        return false;
    }

    /* ------------------------------------------------------------------ Occupation */

    /** @return Collection<int, Block> */
    public function blocksBetween(Spa $spa, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return $spa->blocks()->where('start_at', '<', $end)->where('end_at', '>', $start)->get();
    }

    /**
     * Allocations actives chevauchant [start, end[, prolongées du temps de rotation de leur
     * ressource, en ignorant les réservations inactives même si leurs allocations n'ont pas
     * encore été libérées.
     *
     * @return array<int, array{resource_id:int, party:int, s:CarbonImmutable, e:CarbonImmutable}>
     */
    public function activeAllocations(Spa $spa, CarbonImmutable $start, CarbonImmutable $end, int $excludeBooking = 0): array
    {
        $buffers = $this->bufferByResource($spa);
        $maxBuffer = $buffers ? max($buffers) : 0;

        return Allocation::query()
            ->select('allocations.resource_id', 'allocations.party', 'allocations.start_at', 'allocations.end_at')
            ->join('bookings', 'bookings.id', '=', 'allocations.booking_id')
            ->where('allocations.spa_id', $spa->id)
            ->where('allocations.status', 'active')
            ->where('allocations.booking_id', '<>', $excludeBooking)
            ->where('allocations.start_at', '<', $end->addMinutes($maxBuffer))
            ->where('allocations.end_at', '>', $start->subMinutes($maxBuffer))
            ->whereNotIn('bookings.status', Booking::INACTIVE_STATUSES)
            ->get()
            ->map(fn ($a) => [
                'resource_id' => (int) $a->resource_id,
                'party' => (int) $a->party,
                's' => CarbonImmutable::parse($a->start_at),
                'e' => CarbonImmutable::parse($a->end_at)->addMinutes($buffers[(int) $a->resource_id] ?? 0),
            ])->all();
    }

    private function resourceBlocked(Resource $r, CarbonImmutable $start, CarbonImmutable $end, Collection $blocks): bool
    {
        foreach ($blocks as $b) {
            if (! ($b->start_at < $end && $b->end_at > $start)) {
                continue;
            }
            if ($b->scope === 'spa'
                || ($b->scope === 'type' && $b->resource_type_id === $r->resource_type_id)
                || ($b->scope === 'resource' && $b->resource_id === $r->id)) {
                return true;
            }
        }

        return false;
    }

    /** Occupation maximale (personnes) d'une ressource sur [start, end[. */
    private function peakUsage(int $resourceId, CarbonImmutable $start, CarbonImmutable $end, array $allocs): int
    {
        $mine = array_values(array_filter($allocs, fn ($a) => $a['resource_id'] === $resourceId && $a['s'] < $end && $a['e'] > $start));
        $peak = 0;
        foreach ($mine as $probe) {
            $t = max($probe['s'], $start);
            $sum = 0;
            foreach ($mine as $a) {
                if ($a['s'] <= $t && $a['e'] > $t) {
                    $sum += $a['party'];
                }
            }
            $peak = max($peak, $sum);
        }

        return $peak;
    }

    /* ------------------------------------------------------------------ Planification */

    /**
     * Affecte chaque besoin à des ressources physiques.
     *
     * @return array{ok: bool, allocations: array, errors: string[]}
     */
    public function plan(Spa $spa, array $needs, int $excludeBooking = 0): array
    {
        $result = ['ok' => true, 'allocations' => [], 'errors' => []];
        if (! $needs) {
            return $result;
        }
        $winS = min(array_column($needs, 'start'));
        $winE = max(array_column($needs, 'end'));

        if (! $this->isOpen($spa, $winS, $winE)) {
            return ['ok' => false, 'allocations' => [], 'errors' => [__($spa->hours->isEmpty() ? 'booking.not_configured' : 'booking.closed')]];
        }

        $blocks = $this->blocksBetween($spa, $winS, $winE);
        $buffers = $this->bufferByResource($spa);
        $allocs = $this->activeAllocations($spa, $winS, $winE, $excludeBooking);
        $resources = $spa->resources->where('status', 'active');

        foreach ($needs as $need) {
            $candidates = [];
            foreach ($resources as $r) {
                if ($r->resource_type_id !== $need['type_id'] || $this->resourceBlocked($r, $need['start'], $need['end'], $blocks)) {
                    continue;
                }
                // La ressource doit être libre sur la prestation ET sur sa rotation (nettoyage) qui suit.
                $usage = $this->peakUsage($r->id, $need['start'], $need['end']->addMinutes($buffers[$r->id] ?? 0), $allocs);
                $free = $need['mode'] === 'unit' ? ($usage > 0 ? 0 : $r->max_party) : $r->capacity - $usage;
                if ($free > 0) {
                    $candidates[] = ['res' => $r, 'free' => min($free, $r->max_party)];
                }
            }

            $remaining = $need['party'];
            $chosen = [];
            while ($remaining > 0 && $candidates) {
                // 1) plus petite ressource contenant tout le reste (cabine couple pour 2, pas le hammam de 8) ;
                // 2) sinon la plus grande disponible, le reste est réparti sur d'autres ressources.
                $fit = array_filter($candidates, fn ($c) => $c['free'] >= $remaining && $c['res']->min_party <= $remaining);
                if ($fit) {
                    usort($fit, fn ($a, $b) => $a['free'] <=> $b['free'] ?: $a['res']->sort_order <=> $b['res']->sort_order);
                    $pick = reset($fit);
                    $take = $remaining;
                } else {
                    usort($candidates, fn ($a, $b) => $b['free'] <=> $a['free']);
                    $pick = $candidates[0];
                    $take = min($pick['free'], $remaining);
                    if ($take < $pick['res']->min_party) {
                        break;
                    }
                }
                $chosen[] = ['res' => $pick['res'], 'party' => $take];
                $remaining -= $take;
                $candidates = array_values(array_filter($candidates, fn ($c) => $c['res']->id !== $pick['res']->id));
            }

            if ($remaining > 0) {
                $result['ok'] = false;
                $type = $spa->resourceTypes->firstWhere('id', $need['type_id']);
                $result['errors'][] = __('booking.capacity_insufficient', [
                    'type' => $type?->tr('name') ?? $need['type_slug'],
                    'treatment' => $need['label'],
                    'time' => $need['start']->format('H:i'),
                    'party' => $need['party'],
                    'missing' => $remaining,
                ]);

                continue;
            }

            foreach ($chosen as $c) {
                $alloc = [
                    'spa_id' => $spa->id,
                    'resource_id' => $c['res']->id,
                    'resource_type_id' => $need['type_id'],
                    'treatment_id' => $need['treatment_id'],
                    'participant_no' => $need['participant_no'],
                    'start_at' => $need['start'],
                    'end_at' => $need['end'],
                    'party' => $c['party'],
                    'resource_name' => $c['res']->name,
                ];
                $result['allocations'][] = $alloc;
                $allocs[] = ['resource_id' => $c['res']->id, 'party' => $c['party'], 's' => $need['start'], 'e' => $need['end']->addMinutes($buffers[$c['res']->id] ?? 0)];
            }
        }

        return $result;
    }

    /** Raccourci besoins + plan ; les erreurs de décomposition deviennent un plan refusé. */
    public function check(Spa $spa, CarbonImmutable $start, array $items, int $excludeBooking = 0): array
    {
        try {
            $needs = $this->expandNeeds($spa, $start, $items);
        } catch (BookingException $e) {
            return ['ok' => false, 'allocations' => [], 'errors' => [$e->getMessage()]];
        }

        return $this->plan($spa, $needs, $excludeBooking);
    }

    /* ------------------------------------------------------------------ Créneaux */

    /**
     * Heures de début possibles un jour donné pour une sélection.
     *
     * @return array{times: string[], reason: string}
     */
    public function availability(Spa $spa, string $day, array $items, ?int $step = null, ?CarbonImmutable $notBefore = null): array
    {
        $step = $step ?: $spa->slotStep();
        $notBefore ??= CarbonImmutable::now()->addMinutes($spa->minLead());
        $d0 = CarbonImmutable::parse($day)->startOfDay();
        if ($spa->hours->isEmpty()) {
            return ['times' => [], 'reason' => __('booking.not_configured')];
        }
        $times = [];
        $reason = '';
        for ($m = 0; $m < 1440; $m += $step) {
            $ts = $d0->addMinutes($m);
            if ($ts < $notBefore) {
                continue;
            }
            try {
                $needs = $this->expandNeeds($spa, $ts, $items);
            } catch (BookingException $e) {
                $reason = $e->getMessage();
                break;
            }
            if (! $needs || ! $this->isOpen($spa, $ts, max(array_column($needs, 'end')))) {
                continue;
            }
            $plan = $this->plan($spa, $needs);
            if ($plan['ok']) {
                $times[] = $ts->format('H:i');
            } elseif ($reason === '') {
                $reason = __('booking.first_checked', ['errors' => implode(' ', $plan['errors'])]);
            }
        }

        return ['times' => $times, 'reason' => $times ? '' : ($reason ?: __('booking.no_slot_today'))];
    }

    /** Places libres par type de ressource sur [start, end[ (tableau de bord partenaire). */
    public function freeCapacity(Spa $spa, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $allocs = $this->activeAllocations($spa, $start, $end);
        $blocks = $this->blocksBetween($spa, $start, $end);
        $out = [];
        foreach ($spa->resourceTypes as $type) {
            $free = 0;
            foreach ($spa->resources->where('resource_type_id', $type->id)->where('status', 'active') as $r) {
                if ($this->resourceBlocked($r, $start, $end, $blocks)) {
                    continue;
                }
                $usage = $this->peakUsage($r->id, $start, $end, $allocs);
                $free += $type->isUnit() ? ($usage > 0 ? 0 : $r->capacity) : max(0, $r->capacity - $usage);
            }
            $out[$type->slug] = $free;
        }

        return $out;
    }

    /* ------------------------------------------------------------------ Verrou */

    public function lock(Spa $spa): bool
    {
        $row = DB::selectOne('SELECT GET_LOCK(?, ?) AS l', ['hl_cap_'.$spa->id, config('hl.lock_timeout_seconds')]);

        return (int) ($row->l ?? 0) === 1;
    }

    public function unlock(Spa $spa): void
    {
        DB::selectOne('SELECT RELEASE_LOCK(?) AS l', ['hl_cap_'.$spa->id]);
    }

    /** Exécute un traitement sous le verrou de l'établissement (même connexion DB). */
    public function withLock(Spa $spa, callable $fn): mixed
    {
        if (! $this->lock($spa)) {
            throw BookingException::make('lock', 'busy', [], 409);
        }
        try {
            return $fn();
        } finally {
            $this->unlock($spa);
        }
    }
}
