<?php

namespace App\Console\Commands;

use App\Domain\Media\PhotoPathPolicy;
use App\Models\SpaPhoto;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Déplace les photos d'établissement du dossier public vers le disque privé (rejouable ; ne supprime rien d'autre). */
class PrivatizeSpaPhotos extends Command
{
    protected $signature = 'hl:photos-privatize {--dry-run : Lister sans déplacer}';

    protected $description = "Déplace les photos d'établissement de storage/app/public vers le disque privé (servies par /photos/{id})";

    public function handle(): int
    {
        $paths = SpaPhoto::query()->pluck('path')
            ->merge(DB::table('spa_photo_uploads')->pluck('path'))
            ->filter(fn ($p) => is_string($p) && PhotoPathPolicy::isSafe($p) && ! PhotoPathPolicy::isExternal($p))
            ->unique()->values();

        $moved = $already = $missing = 0;
        foreach ($paths as $path) {
            if (Storage::disk(SpaPhoto::DISK)->exists($path)) {
                $already++;
                if (Storage::disk('public')->exists($path) && ! $this->option('dry-run')) {
                    Storage::disk('public')->delete($path);
                }

                continue;
            }
            if (! Storage::disk('public')->exists($path)) {
                $missing++;
                $this->warn("Absent sur les deux disques : $path");

                continue;
            }
            $moved++;
            if ($this->option('dry-run')) {
                $this->line("À déplacer : $path");

                continue;
            }
            Storage::disk(SpaPhoto::DISK)->put($path, Storage::disk('public')->get($path));
            Storage::disk('public')->delete($path);
        }

        $this->info(sprintf('%s : %d déplacée(s), %d déjà privée(s), %d introuvable(s).', $this->option('dry-run') ? 'Simulation' : 'Terminé', $moved, $already, $missing));

        return self::SUCCESS;
    }
}
