<?php

namespace App\Console\Commands;

use App\Domain\Booking\BookingMutation;
use App\Domain\Catalogue\PublicationChecklist;
use App\Domain\Media\PhotoProcessor;
use App\Domain\Partner\OnboardingService;
use App\Models\Amenity;
use App\Models\Category;
use App\Models\City;
use App\Models\Partner;
use App\Models\ResourceType;
use App\Models\Spa;
use App\Models\Treatment;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Import rejouable d'un établissement complet depuis un fichier JSON : compte partenaire, fiche, horaires,
 * capacité (ressources), soins avec étapes, extras et photos. Relancer la commande met à jour sans doublon
 * (clés : e-mail du partenaire, slug de l'établissement, slug de chaque soin).
 */
class ImportSpaCatalogue extends Command
{
    protected $signature = 'hl:import-spa {file : fichier JSON du catalogue}
        {--photos-dir= : dossier contenant les photos listées dans le fichier (importées seulement si la fiche n’a encore aucune photo)}
        {--publish : publie la fiche (sinon elle reste « à valider » par l’Admin)}
        {--password= : mot de passe initial du compte partenaire s’il est créé (aléatoire sinon)}';

    protected $description = 'Importe ou met à jour un établissement et son catalogue depuis un fichier JSON (idempotent)';

    public function handle(OnboardingService $onboarding, BookingMutation $mutations): int
    {
        $file = (string) $this->argument('file');
        if (! is_file($file)) {
            $this->error("Fichier introuvable : $file");

            return self::FAILURE;
        }
        $spec = json_decode((string) file_get_contents($file), true);

        try {
            $this->validate($spec);
            $partner = $this->partner($spec['partner']);
            $spa = $this->spa($partner, $spec['spa']);
            $onboarding->saveHours($spa, $this->hours($spec['spa']['hours'] ?? []), $spec['spa']['capacity'] ?? []);
            $extras = collect($spec['extras'] ?? [])->keyBy('code');
            $counts = $mutations->forSpa($spa, fn (Spa $current) => $this->catalogue($current, $spec['treatments'], $extras));
            $photos = $this->photos($spa, $spec['photos'] ?? []);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $spa->refresh();
        $spa->refreshPriceFrom();
        if ($this->option('publish')) {
            $spa->update(['status' => 'published', 'published_at' => $spa->published_at ?? now()]);
        }

        $this->line(json_encode([
            'ok' => true,
            'partner' => ['id' => $partner->id, 'email' => $partner->user->email, 'status' => $partner->status],
            'spa' => ['id' => $spa->id, 'slug' => $spa->slug, 'status' => $spa->fresh()->status, 'price_from' => $spa->price_from],
            'resources' => $onboarding->capacityOf($spa),
            'photos_imported' => $photos,
            'missing_for_publication' => PublicationChecklist::failures($spa),
        ] + $counts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }

    private function validate(mixed $spec): void
    {
        if (! is_array($spec) || ! isset($spec['partner']['email'], $spec['partner']['company_name'], $spec['spa']['slug'], $spec['spa']['name'], $spec['spa']['city'])
            || ! is_array($spec['treatments'] ?? null) || $spec['treatments'] === []) {
            throw new InvalidArgumentException('Fichier invalide : partner.email, partner.company_name, spa.slug, spa.name, spa.city et au moins un soin sont requis.');
        }
        if (! City::where('name_fr', $spec['spa']['city'])->exists()) {
            throw new InvalidArgumentException('Ville inconnue : '.$spec['spa']['city']);
        }
        $extras = collect($spec['extras'] ?? [])->pluck('code');
        foreach ($spec['treatments'] as $i => $t) {
            $label = $t['code'] ?? ('#'.($i + 1));
            if (! isset($t['code'], $t['name_fr'], $t['price_per_person']) || ! in_array($t['category'] ?? null, Treatment::CATEGORIES, true)) {
                throw new InvalidArgumentException("Soin $label : code, name_fr, category (".implode('/', Treatment::CATEGORIES).') et price_per_person sont requis.');
            }
            if (! is_array($t['steps'] ?? null) || $t['steps'] === []) {
                throw new InvalidArgumentException("Soin $label : au moins une étape (kind, duration_min) est requise.");
            }
            foreach ($t['steps'] as $s) {
                if (! isset(OnboardingService::RESOURCE_TYPES[$s['kind'] ?? '']) || ($s['kind'] ?? null) === 'praticien' || (int) ($s['duration_min'] ?? 0) <= 0) {
                    throw new InvalidArgumentException("Soin $label : étape invalide (kind hammam/massage/soin, duration_min > 0).");
                }
            }
            foreach ($t['extras'] ?? [] as $code) {
                if (! $extras->contains($code)) {
                    throw new InvalidArgumentException("Soin $label : extra inconnu « $code ».");
                }
            }
        }
    }

    /**
     * Compte partenaire approuvé. Un compte existant n'est jamais modifié (ni mot de passe, ni nom) et un e-mail
     * déjà rattaché à un autre partenaire ou à un compte non partenaire est refusé : l'import ne reprend jamais un compte.
     */
    private function partner(array $p): Partner
    {
        $user = User::where('email', $p['email'])->first();
        if ($user && $user->role !== 'partner') {
            throw new InvalidArgumentException('L’e-mail '.$p['email'].' appartient à un compte « '.$user->role.' », pas à un partenaire.');
        }
        $existing = $user ? Partner::where('user_id', $user->id)->first() : null;
        if ($existing && $existing->company_name !== $p['company_name']) {
            throw new InvalidArgumentException('L’e-mail '.$p['email'].' est déjà utilisé par le partenaire « '.$existing->company_name.' ».');
        }
        if ($existing) {
            return $existing;
        }
        if (! $user) {
            $user = User::create([
                'name' => trim(($p['first_name'] ?? '').' '.($p['last_name'] ?? '')) ?: $p['company_name'],
                'first_name' => $p['first_name'] ?? null,
                'last_name' => $p['last_name'] ?? null,
                'email' => $p['email'],
                'password' => Hash::make((string) ($this->option('password') ?: Str::password(20))),
                'role' => 'partner',
                'phone' => $p['phone'] ?? null,
                'locale' => $p['locale'] ?? 'fr',
                'email_verified_at' => now(),
            ]);
        }

        return Partner::create([
            'user_id' => $user->id,
            'company_name' => $p['company_name'],
            'contact_phone' => $p['phone'] ?? null,
            'status' => 'approved',
            'commission_pct' => $p['commission_pct'] ?? null,
        ]);
    }

    private function spa(Partner $partner, array $s): Spa
    {
        $spa = Spa::firstOrNew(['slug' => $s['slug']]);
        $spa->fill([
            'partner_id' => $partner->id,
            'name' => $s['name'],
            'category' => $s['category'] ?? 'hammam',
            'city' => $s['city'],
            'city_id' => City::where('name_fr', $s['city'])->value('id'),
            'area' => $s['area'] ?? null,
            'address' => $s['address'] ?? null,
            'lat' => $s['lat'] ?? null,
            'lng' => $s['lng'] ?? null,
            'phone' => $s['phone'] ?? null,
            'email' => $s['email'] ?? null,
            'website' => $s['website'] ?? null,
            'description_fr' => $s['description_fr'] ?? null,
            'description_en' => $s['description_en'] ?? null,
            'practical_info' => $s['practical_info'] ?? null,
            'cancellation_hours' => $s['cancellation_hours'] ?? 24,
            'license_number' => $s['license_number'] ?? null,
            'license_authority' => $s['license_authority'] ?? null,
        ]);
        if (! $spa->exists) {
            $spa->status = 'pending';
            $spa->submitted_at = now();
        }
        $spa->save();
        $spa->categories()->sync(Category::whereIn('slug', $s['categories'] ?? [])->pluck('id'));
        $spa->amenities()->sync(Amenity::whereIn('slug', $s['amenities'] ?? [])->pluck('id'));

        return $spa;
    }

    /** @return list<array{weekday:int, opens_min:int, closes_min:int}> */
    private function hours(array $hours): array
    {
        return array_map(fn (array $h) => [
            'weekday' => (int) $h['weekday'],
            'opens_min' => self::minutes($h['opens']),
            'closes_min' => self::minutes($h['closes']),
        ], array_values($hours));
    }

    private static function minutes(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm) + [1 => 0]);

        return $h * 60 + $m;
    }

    /** @return array{treatments:int, extras:int, deactivated:int} */
    private function catalogue(Spa $spa, array $rows, Collection $extras): array
    {
        $keep = [];
        $nExtras = 0;
        foreach (array_values($rows) as $i => $row) {
            $slug = $row['slug'] ?? Str::slug($row['code'].' '.$row['name_fr']);
            $keep[] = $slug;
            $duration = array_sum(array_map(fn ($s) => (int) $s['duration_min'], $row['steps']));
            $t = $spa->treatments()->updateOrCreate(['slug' => $slug], [
                'category' => $row['category'],
                'name_fr' => $row['name_fr'],
                'name_en' => $row['name_en'] ?? null,
                'description_fr' => $row['description_fr'] ?? null,
                'description_en' => $row['description_en'] ?? null,
                'duration_min' => $duration,
                'price_solo' => $row['price_per_person'],
                'price_couple' => $row['price_couple'] ?? null,
                'price_group' => $row['price_group'] ?? null,
                'party_min' => (int) ($row['party_min'] ?? 1),
                'party_max' => (int) ($row['party_max'] ?? 4),
                'included' => $row['included'] ?? null,
                'sort_order' => $i,
                'status' => 'active',
            ]);
            $t->steps()->delete();
            $offset = 0;
            foreach (array_values($row['steps']) as $k => $step) {
                $t->steps()->create([
                    'resource_type_id' => $this->resourceType($spa, $step['kind'])->id,
                    'label' => $step['label_fr'] ?? null,
                    'label_en' => $step['label_en'] ?? null,
                    'duration_min' => (int) $step['duration_min'],
                    'offset_min' => $offset,
                    'staff_per_person' => OnboardingService::staffFor($step['kind'], $step['staff'] ?? null),
                    'position' => $k,
                ]);
                $offset += (int) $step['duration_min'];
            }
            $t->extras()->delete();
            foreach ($row['extras'] ?? [] as $code) {
                $e = $extras->get($code);
                $t->extras()->create([
                    'spa_id' => $spa->id,
                    'name_fr' => $e['name_fr'],
                    'name_en' => $e['name_en'] ?? null,
                    'price' => $e['price'],
                    'extra_min' => (int) ($e['extra_min'] ?? 0),
                    'applies_to' => $e['applies_to'] ?? null,
                    'per_person' => (bool) ($e['per_person'] ?? true),
                    'max_qty' => (int) ($e['max_qty'] ?? 1),
                    'status' => 'active',
                ]);
                $nExtras++;
            }
        }
        $deactivated = $spa->treatments()->whereNotIn('slug', $keep)->where('status', 'active')->update(['status' => 'inactive']);

        return ['treatments' => count($keep), 'extras' => $nExtras, 'deactivated' => $deactivated];
    }

    private function resourceType(Spa $spa, string $kind): ResourceType
    {
        [$slug, $fr, $en, $mode] = OnboardingService::RESOURCE_TYPES[$kind];

        return $spa->resourceTypes()->firstOrCreate(['slug' => $slug], [
            'name_fr' => $fr, 'name_en' => $en, 'allocation_mode' => $mode, 'kind' => OnboardingService::RESOURCE_TYPES[$kind][4] ?? 'room',
        ]);
    }

    /** Photos traitées comme un envoi du partenaire (redimensionnement, JPEG) ; ignorées si la fiche en a déjà. */
    private function photos(Spa $spa, array $files): int
    {
        $dir = $this->option('photos-dir');
        if (! $dir || $files === [] || $spa->photos()->exists()) {
            return 0;
        }
        $n = 0;
        foreach (array_slice($files, 0, (int) config('hl.max_photos')) as $file) {
            try {
                $image = PhotoProcessor::process(rtrim((string) $dir, '/').'/'.basename((string) $file));
            } catch (InvalidArgumentException $e) {
                $this->warn("Photo ignorée ($file) : ".$e->getMessage());

                continue;
            }
            $path = 'spas/'.$spa->id.'/'.Str::uuid().'.jpg';
            Storage::disk('public')->put($path, $image, 'public');
            $spa->photos()->create(['path' => $path, 'sort_order' => $n, 'is_cover' => $n === 0, 'caption_fr' => $spa->name, 'caption_en' => $spa->name]);
            $n++;
        }

        return $n;
    }
}
