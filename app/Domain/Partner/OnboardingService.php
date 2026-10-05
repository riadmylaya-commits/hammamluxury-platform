<?php

namespace App\Domain\Partner;

use App\Domain\Catalogue\Presentation;
use App\Mail\SpaStatusMail;
use App\Models\ActivityLog;
use App\Models\City;
use App\Models\Partner;
use App\Models\Spa;
use App\Models\Treatment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Assistant « Référencer mon spa » : chaque étape est enregistrée dès qu'elle est validée
 * (brouillon `status = draft`, `onboarding_step` = dernière étape validée), reprise possible plus tard.
 * La soumission passe l'établissement en `pending` ; la publication reste une décision de l'administration.
 */
class OnboardingService
{
    public const STEPS = ['account', 'spa', 'photos', 'services', 'treatments', 'hours', 'summary'];

    /** Étape de soin → type de ressource provisionné automatiquement (slug, nom FR, nom EN, mode). */
    public const RESOURCE_TYPES = [
        'hammam' => ['hammam', 'Hammam', 'Hammam', 'pool'],
        'massage' => ['massage', 'Cabine de massage', 'Massage cabin', 'unit'],
        'soin' => ['soin', 'Salle de soin', 'Treatment room', 'unit'],
        'praticien' => ['praticien', 'Praticien(ne)', 'Therapist', 'unit', 'therapist'],
    ];

    /** Composantes qui mobilisent un(e) praticien(ne) par personne en plus de la cabine/salle. */
    public const STAFFED_KINDS = ['massage', 'soin'];

    public const BUFFER_OPTIONS = [0, 5, 10, 15, 20, 30];

    /** Brouillon en cours du partenaire (le plus récent), ou celui demandé s'il lui appartient. */
    public function currentDraft(Partner $partner, ?int $spaId = null): ?Spa
    {
        $q = $partner->spas()->whereNotNull('onboarding_step')->where('status', 'draft');

        return $spaId ? $q->whereKey($spaId)->first() : $q->latest('updated_at')->first();
    }

    /** @param  array<string, mixed>  $data */
    public function saveAccount(User $user, array $data): void
    {
        $user->fill([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'phone' => $data['phone'],
            'whatsapp' => ($data['whatsapp_same'] ?? false) ? $data['phone'] : ($data['whatsapp'] ?? null),
        ])->save();
        $user->partner?->update(['company_name' => $data['company_name'], 'contact_phone' => $data['phone']]);
    }

    /** @param  array<string, mixed>  $data */
    public function saveSpa(Partner $partner, ?Spa $spa, array $data): Spa
    {
        $attrs = collect($data)->only(['name', 'category', 'city_id', 'area', 'address', 'description_fr', 'description_en', 'phone', 'whatsapp', 'email', 'website', 'location', 'practical_info', ...Spa::LICENSE_FIELDS])->all();

        if ($spa) {
            $spa->update($attrs);

            return $spa->refresh();
        }

        $spa = $partner->spas()->create($attrs + [
            'slug' => $this->uniqueSlug($attrs['name'], $attrs['city_id'] ?? null),
            'status' => 'draft',
            'onboarding_step' => 1,
        ]);
        ActivityLog::record('spa.draft_created', $spa, ['name' => $spa->name]);

        return $spa;
    }

    /** @param  list<string>  $paths chemins déjà traités (PhotoProcessor), dans l'ordre choisi */
    public function savePhotos(Spa $spa, array $paths): void
    {
        $paths = array_values(array_unique(array_filter($paths)));
        $max = (int) config('hl.max_photos');
        if (count($paths) > $max) {
            throw ValidationException::withMessages(['data.photos' => __('partner.photos_max_error', ['max' => $max])]);
        }
        $spa->photos()->whereNotIn('path', $paths)->delete();
        foreach ($paths as $i => $path) {
            $spa->photos()->updateOrCreate(['path' => $path], ['sort_order' => $i, 'is_cover' => $i === 0]);
        }
    }

    /**
     * @param  list<int>  $categoryIds
     * @param  list<int>  $amenityIds
     */
    public function saveServices(Spa $spa, array $categoryIds, array $amenityIds): void
    {
        $spa->categories()->sync($categoryIds);
        $spa->amenities()->sync($amenityIds);
    }

    /**
     * @param  list<array<string, mixed>>  $rows  name_fr, name_en, category, duration_min, price_solo, price_couple, description_fr,
     *                                            included[], featured (bool), featured_badge, components[] {kind, label, duration_min} pour les formules
     */
    public function saveTreatments(Spa $spa, array $rows): void
    {
        $keep = [];
        $featured = null;
        foreach (array_values($rows) as $i => $row) {
            $slug = Str::slug($row['name_fr']) ?: 'soin-'.($i + 1);
            $n = 1;
            $base = $slug;
            while (in_array($slug, $keep, true)) {
                $slug = $base.'-'.++$n;
            }
            $keep[] = $slug;

            $components = ($row['category'] ?? null) === 'ritual' ? $this->cleanComponents($row['components'] ?? []) : [];
            $duration = $components ? self::componentsDuration($components) : (int) ($row['duration_min'] ?? 0);

            $treatment = $spa->treatments()->updateOrCreate(['slug' => $slug], [
                'category' => $row['category'],
                'name_fr' => $row['name_fr'],
                'name_en' => $row['name_en'] ?? null,
                'description_fr' => $row['description_fr'] ?? null,
                'duration_min' => $duration,
                'price_solo' => $row['price_solo'],
                'price_couple' => $row['price_couple'] ?? null,
                'party_min' => 1,
                'party_max' => (int) ($row['party_max'] ?? 6),
                'included' => Presentation::cleanIncluded($row['included'] ?? null) ?: null,
                'sort_order' => $i,
                'status' => 'active',
            ]);
            if (! empty($row['featured']) && $featured === null) {
                $featured = [$treatment, $row['featured_badge'] ?? null];
            }
            if ($components) {
                $this->syncComponentSteps($treatment, $components);
            } else {
                $this->syncDefaultSteps($treatment, self::staffFor($row['category'], $row['staff_per_person'] ?? null));
            }
        }
        $spa->treatments()->whereNotIn('slug', $keep)->delete();

        if ($featured) {
            [$t, $badge] = $featured;
            $t->update(['featured_badge' => in_array($badge, Presentation::BADGES, true) ? $badge : ($t->featured_badge ?? 'signature')]);
        } else {
            $spa->treatments()->whereNotNull('featured_badge')->update(['featured_badge' => null]);
        }
    }

    /** Praticien(ne)s par personne : 1 par défaut pour un massage ou un soin, 2 pour un « quatre mains », 0 pour le hammam. */
    public static function staffFor(?string $kind, mixed $requested = null): int
    {
        if (! in_array($kind, self::STAFFED_KINDS, true)) {
            return 0;
        }

        return $requested === null || $requested === '' ? 1 : max(0, min(2, (int) $requested));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{kind:string, label:?string, duration_min:int, staff:int, parallel:bool}>
     */
    private function cleanComponents(array $rows): array
    {
        $out = [];
        foreach ($rows as $c) {
            $kind = $c['kind'] ?? null;
            $min = (int) ($c['duration_min'] ?? 0);
            if (! isset(self::RESOURCE_TYPES[$kind]) || $kind === 'praticien' || $min <= 0) {
                continue;
            }
            $out[] = [
                'kind' => $kind,
                'label' => filled($c['label'] ?? null) ? trim($c['label']) : null,
                'duration_min' => $min,
                'staff' => self::staffFor($kind, $c['staff'] ?? null),
                'parallel' => $out !== [] && ! empty($c['parallel']),
            ];
        }

        return $out;
    }

    /**
     * Début de chaque composante : enchaînée après la précédente, ou en même temps qu'elle si « parallèle ».
     *
     * @param  list<array{duration_min:int, parallel:bool}>  $components
     * @return list<int>
     */
    public static function componentOffsets(array $components): array
    {
        $offsets = [];
        $end = 0;
        foreach ($components as $i => $c) {
            $offsets[$i] = $i > 0 && $c['parallel'] ? $offsets[$i - 1] : $end;
            $end = max($end, $offsets[$i] + (int) $c['duration_min']);
        }

        return $offsets;
    }

    /** @param  list<array{duration_min:int, parallel:bool}>  $components */
    public static function componentsDuration(array $components): int
    {
        $end = 0;
        foreach (self::componentOffsets($components) as $i => $offset) {
            $end = max($end, $offset + (int) $components[$i]['duration_min']);
        }

        return $end;
    }

    /**
     * Étapes explicites d'une formule : une par composante, enchaînées dans l'ordre. Les types de ressources
     * sont créés si besoin ; les ressources elles-mêmes sont provisionnées à l'étape horaires/capacité.
     *
     * @param  list<array{kind:string, label:?string, duration_min:int, staff:int, parallel:bool}>  $components
     */
    private function syncComponentSteps(Treatment $treatment, array $components): void
    {
        $treatment->steps()->delete();
        $offsets = self::componentOffsets($components);
        foreach ($components as $i => $c) {
            $treatment->steps()->create([
                'resource_type_id' => $this->resourceType($treatment->spa, $c['kind'])->id,
                'label' => $c['label'],
                'duration_min' => $c['duration_min'],
                'offset_min' => $offsets[$i],
                'staff_per_person' => $c['staff'],
                'parallel_with_previous' => $c['parallel'],
                'position' => $i,
            ]);
        }
    }

    /**
     * @param  list<array{weekday:int, opens_min:int, closes_min:int}>  $hours
     * @param  array{hammam_capacity?:int, massage_cabins?:int, treatment_rooms?:int, therapists?:int, cabin_buffer_min?:int}  $capacity
     */
    public function saveHours(Spa $spa, array $hours, array $capacity): void
    {
        $spa->hours()->delete();
        foreach ($hours as $h) {
            if ((int) $h['closes_min'] > (int) $h['opens_min']) {
                $spa->hours()->create(['weekday' => (int) $h['weekday'], 'opens_min' => (int) $h['opens_min'], 'closes_min' => (int) $h['closes_min']]);
            }
        }
        $this->provisionResources($spa, $capacity);
    }

    /** Crée/ajuste les ressources par défaut sans toucher à celles déjà personnalisées par le partenaire. */
    public function provisionResources(Spa $spa, array $capacity): void
    {
        $hammam = max(0, (int) ($capacity['hammam_capacity'] ?? 0));
        $cabins = max(0, (int) ($capacity['massage_cabins'] ?? 0));
        $rooms = max(0, (int) ($capacity['treatment_rooms'] ?? 0));
        $therapists = max(0, (int) ($capacity['therapists'] ?? 0));
        $buffer = array_key_exists('cabin_buffer_min', $capacity) ? max(0, (int) $capacity['cabin_buffer_min']) : null;

        if ($hammam > 0) {
            $type = $this->resourceType($spa, 'hammam');
            $type->resources()->updateOrCreate(['spa_id' => $spa->id, 'name' => 'Hammam'], ['capacity' => $hammam, 'min_party' => 1, 'max_party' => $hammam, 'status' => 'active']);
        }
        foreach (['massage' => $cabins, 'soin' => $rooms, 'praticien' => $therapists] as $slug => $count) {
            if ($count <= 0) {
                continue;
            }
            $type = $this->resourceType($spa, $slug);
            if ($buffer !== null && $slug !== 'praticien') {
                $type->update(['buffer_min' => $buffer]);
            }
            $prefix = match ($slug) {
                'massage' => 'Cabine',
                'soin' => 'Salle de soin',
                default => 'Praticien(ne)',
            };
            for ($i = 1; $i <= $count; $i++) {
                $type->resources()->updateOrCreate(['spa_id' => $spa->id, 'name' => "$prefix $i"], ['capacity' => 1, 'min_party' => 1, 'max_party' => 1, 'status' => 'active', 'sort_order' => $i]);
            }
            $type->resources()->where('name', 'like', "$prefix %")->get()
                ->filter(fn ($r) => (int) Str::afterLast($r->name, ' ') > $count)->each->update(['status' => 'inactive']);
        }
        foreach ($spa->treatments as $treatment) {
            $this->syncDefaultSteps($treatment);
        }
    }

    /** @return array{hammam_capacity:int, massage_cabins:int, treatment_rooms:int, therapists:int, cabin_buffer_min:int} */
    public function capacityOf(Spa $spa): array
    {
        $active = $spa->resources()->where('status', 'active')->with('type')->get()->groupBy(fn ($r) => $r->type->slug);
        $buffers = $spa->resourceTypes()->whereIn('slug', ['massage', 'soin'])->pluck('buffer_min');

        return [
            'hammam_capacity' => (int) $active->get('hammam', collect())->sum('capacity'),
            'massage_cabins' => $active->get('massage', collect())->count(),
            'treatment_rooms' => $active->get('soin', collect())->count(),
            'therapists' => $active->get('praticien', collect())->count()
                + $spa->resources()->where('status', 'active')->whereHas('type', fn ($q) => $q->where('kind', 'therapist')->where('slug', '!=', 'praticien'))->count(),
            'cabin_buffer_min' => (int) ($buffers->max() ?? 0),
        ];
    }

    /** Un établissement proposant massages ou soins doit indiquer au moins un(e) praticien(ne). */
    public static function therapistsMissing(array $capacity): bool
    {
        return ((int) ($capacity['massage_cabins'] ?? 0) + (int) ($capacity['treatment_rooms'] ?? 0)) > 0
            && (int) ($capacity['therapists'] ?? 0) < 1;
    }

    public function markStep(Spa $spa, int $step): void
    {
        if ($spa->onboarding_step !== null && $step > $spa->onboarding_step) {
            $spa->update(['onboarding_step' => $step]);
        }
    }

    /** Envoi pour validation : `pending`, fin de l'assistant, notifications admin. */
    public function submit(Spa $spa): void
    {
        DB::transaction(function () use ($spa) {
            $spa->refreshPriceFrom();
            $spa->update(['status' => 'pending', 'onboarding_step' => null, 'submitted_at' => now(), 'status_note' => null]);
            ActivityLog::record('spa.submitted', $spa, ['name' => $spa->name]);
        });

        foreach (User::where('role', 'admin')->pluck('email') as $email) {
            Mail::to($email)->queue(new SpaStatusMail($spa, 'submitted', 'admin'));
        }
    }

    /** Décision admin : publication ou refus (motif dans status_note), avec e-mail au partenaire. */
    public static function notifyDecision(Spa $spa, string $decision): void
    {
        if ($decision === 'refused' && $spa->onboarding_step === null) {
            $spa->update(['onboarding_step' => count(self::STEPS) - 1]);
        }
        ActivityLog::record("spa.$decision", $spa, array_filter(['name' => $spa->name, 'note' => $spa->status_note]));
        if ($email = $spa->partner?->user?->email) {
            Mail::to($email)->queue(new SpaStatusMail($spa, $decision, 'partner'));
        }
    }

    private function syncDefaultSteps(Treatment $treatment, ?int $staff = null): void
    {
        if ($treatment->steps()->exists()) {
            if ($staff !== null) {
                $treatment->steps()->whereHas('resourceType', fn ($q) => $q->whereIn('slug', self::STAFFED_KINDS))->update(['staff_per_person' => $staff]);
            }

            return;
        }
        $spa = $treatment->spa;
        if (in_array($treatment->category, ['hammam', ...self::STAFFED_KINDS], true)) {
            $this->resourceType($spa, $treatment->category);
        }
        $types = $spa->resourceTypes()->pluck('id', 'slug');
        $duration = (int) $treatment->duration_min;

        $plan = match ($treatment->category) {
            'hammam' => [['hammam', $duration]],
            'massage' => [['massage', $duration]],
            'ritual' => $types->has('hammam') && $types->has('massage')
                ? [['hammam', (int) floor($duration / 2)], ['massage', $duration - (int) floor($duration / 2)]]
                : [[$types->has('massage') ? 'massage' : 'hammam', $duration]],
            default => [[$types->has('soin') ? 'soin' : 'massage', $duration]],
        };

        $offset = 0;
        foreach ($plan as $i => [$slug, $min]) {
            if (! $types->has($slug) || $min <= 0) {
                $treatment->steps()->delete();

                return;
            }
            $treatment->steps()->create([
                'resource_type_id' => $types[$slug],
                'duration_min' => $min,
                'offset_min' => $offset,
                'staff_per_person' => $staff ?? self::staffFor($slug),
                'position' => $i,
            ]);
            $offset += $min;
        }
    }

    private function resourceType(Spa $spa, string $slug)
    {
        [$s, $fr, $en, $mode] = self::RESOURCE_TYPES[$slug];
        $kind = self::RESOURCE_TYPES[$slug][4] ?? 'room';

        return $spa->resourceTypes()->firstOrCreate(['slug' => $s], ['name_fr' => $fr, 'name_en' => $en, 'allocation_mode' => $mode, 'kind' => $kind, 'sort_order' => array_search($slug, array_keys(self::RESOURCE_TYPES), true)]);
    }

    private function uniqueSlug(string $name, ?int $cityId): string
    {
        $city = $cityId ? City::find($cityId)?->name_fr : null;
        $base = Str::slug(trim($name.' '.$city)) ?: 'spa';
        $slug = $base;
        $n = 1;
        while (Spa::where('slug', $slug)->exists()) {
            $slug = $base.'-'.++$n;
        }

        return $slug;
    }
}
