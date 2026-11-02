<?php

namespace App\Console\Commands;

use App\Models\Spa;
use App\Models\SpaPhoto;
use App\Models\Treatment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Applique à une fiche existante un lot de corrections sourcées (fichier JSON), champ par champ, sans import complet :
 * aucun soin créé, supprimé ou (ré)activé, aucune photo supprimée (les photos douteuses sont isolées, hidden_at).
 * Idempotent ; les valeurs remplacées sont sauvegardées dans storage/app/corrections-backup/ avant écriture.
 */
class ApplySpaCorrections extends Command
{
    protected $signature = 'hl:apply-corrections {file : fichier JSON de corrections}
        {--dry-run : afficher les écarts sans rien écrire}';

    protected $description = 'Applique un lot de corrections sourcées à un établissement (prix, durées, coordonnées, photos isolées) avec sauvegarde préalable';

    /** Champs modifiables de la fiche. */
    private const SPA_FIELDS = ['phone', 'whatsapp', 'email', 'website', 'address', 'area', 'google_maps_url', 'google_place_id', 'cancellation_hours'];

    /** Champs modifiables d'un soin (jamais `status`, ni les étapes). */
    private const TREATMENT_FIELDS = ['duration_min', 'price_solo', 'price_couple', 'price_group', 'party_min', 'party_max', 'name_fr', 'name_en', 'description_fr', 'description_en'];

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        if (! is_file($file)) {
            $this->error("Fichier introuvable : $file");

            return self::FAILURE;
        }
        $spec = json_decode((string) file_get_contents($file), true);
        if (! is_array($spec) || ! isset($spec['spa']) || ! is_string($spec['spa'])) {
            $this->error('Fichier invalide : clé "spa" (slug) requise.');

            return self::FAILURE;
        }
        $spa = Spa::query()->where('slug', $spec['spa'])->first();
        if ($spa === null) {
            $this->error('Établissement introuvable : '.$spec['spa']);

            return self::FAILURE;
        }

        try {
            $changes = $this->plan($spa, $spec);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($changes as $c) {
            $this->line(sprintf('%s %s.%s : %s → %s', $c['apply'] ? '•' : '=', $c['target'], $c['field'], $this->show($c['before']), $this->show($c['after'])));
        }
        $todo = array_values(array_filter($changes, fn ($c) => $c['apply']));
        if ($todo === []) {
            $this->info('Aucun écart : rien à écrire.');

            return self::SUCCESS;
        }
        if ($this->option('dry-run')) {
            $this->info(count($todo).' écart(s) à appliquer (dry-run, rien écrit).');

            return self::SUCCESS;
        }

        $backup = 'corrections-backup/'.now()->format('Ymd-His').'-'.$spa->slug.'.json';
        Storage::disk('local')->put($backup, json_encode([
            'spa_id' => $spa->id, 'slug' => $spa->slug, 'file' => basename($file), 'at' => now()->toIso8601String(),
            'before' => array_map(fn ($c) => ['target' => $c['target'], 'id' => $c['id'], 'field' => $c['field'], 'value' => $c['before']], $todo),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        DB::transaction(function () use ($spa, $todo) {
            foreach ($todo as $c) {
                match ($c['target']) {
                    'spa' => $spa->forceFill([$c['field'] => $c['after']])->saveQuietly(),
                    'treatment' => Treatment::query()->whereKey($c['id'])->update([$c['column'] => $c['after']]),
                    'photo' => SpaPhoto::query()->whereKey($c['id'])->update([
                        'hidden_at' => $c['after'] === null ? null : now(),
                        'hidden_reason' => $c['after'],
                    ]),
                };
            }
            $spa->refresh()->refreshPriceFrom();
        });

        $this->info(count($todo).' correction(s) appliquée(s) à '.$spa->slug.' ; sauvegarde : storage/app/'.$backup);

        return self::SUCCESS;
    }

    /** @return array<int, array{target:string,id:int|null,field:string,column?:string,before:mixed,after:mixed,apply:bool}> */
    private function plan(Spa $spa, array $spec): array
    {
        $out = [];
        foreach ((array) ($spec['fields'] ?? []) as $field => $value) {
            if (! in_array($field, self::SPA_FIELDS, true)) {
                throw new InvalidArgumentException("Champ de fiche non autorisé : $field");
            }
            $before = $spa->getAttribute($field);
            $out[] = ['target' => 'spa', 'id' => $spa->id, 'field' => $field, 'before' => $before, 'after' => $value, 'apply' => ! $this->same($before, $value)];
        }

        foreach ((array) ($spec['treatments'] ?? []) as $slug => $fields) {
            $t = $spa->treatments()->where('slug', $slug)->first();
            if ($t === null) {
                throw new InvalidArgumentException("Soin introuvable : $slug (aucun soin n'est créé par cette commande)");
            }
            foreach ((array) $fields as $field => $value) {
                if (! in_array($field, self::TREATMENT_FIELDS, true)) {
                    throw new InvalidArgumentException("Champ de soin non autorisé : $slug.$field (le statut et les étapes ne sont pas modifiables ici)");
                }
                if ($field === 'duration_min' && $t->steps()->exists() && (int) $value !== $t->computedDuration()) {
                    throw new InvalidArgumentException("$slug : la durée est déterminée par les étapes ({$t->computedDuration()} min) ; corriger les étapes, pas duration_min");
                }
                $before = $t->getAttribute($field);
                $out[] = ['target' => 'treatment', 'id' => $t->id, 'field' => "$slug.$field", 'column' => $field, 'before' => $before, 'after' => $value, 'apply' => ! $this->same($before, $value)];
            }
            if (($fields['party_min'] ?? $t->party_min) > ($fields['party_max'] ?? $t->party_max)) {
                throw new InvalidArgumentException("$slug : party_min > party_max");
            }
        }

        foreach ((array) ($spec['hide_photos'] ?? []) as $row) {
            $suffix = (string) ($row['path_ends_with'] ?? '');
            $reason = (string) ($row['reason'] ?? 'provenance ou droits à confirmer');
            if ($suffix === '') {
                throw new InvalidArgumentException('hide_photos : path_ends_with requis');
            }
            $photos = $spa->photos()->where('path', 'like', '%'.$suffix)->get();
            if ($photos->count() !== 1) {
                throw new InvalidArgumentException("hide_photos : $suffix correspond à {$photos->count()} photo(s) (1 attendue)");
            }
            $p = $photos->first();
            if ($p->is_cover && $spa->photos()->whereNull('hidden_at')->whereKeyNot($p->id)->doesntExist()) {
                throw new InvalidArgumentException("hide_photos : $suffix est la seule photo visible (couverture)");
            }
            $out[] = ['target' => 'photo', 'id' => $p->id, 'field' => 'hidden ('.$suffix.')', 'before' => $p->hidden_reason, 'after' => $reason, 'apply' => $p->hidden_at === null || $p->hidden_reason !== $reason];
        }

        return $out;
    }

    private function same(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return abs((float) $a - (float) $b) < 0.005;
        }

        return (string) $a === (string) $b;
    }

    private function show(mixed $v): string
    {
        return $v === null ? 'null' : (string) $v;
    }
}
