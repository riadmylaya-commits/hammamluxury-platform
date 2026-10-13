<?php

namespace App\Console\Commands;

use App\Models\Spa;
use App\Models\User;
use Illuminate\Console\Command;

/** Garde anti-démo : en production, aucun compte ni établissement de démonstration ne doit exister. */
class DemoGuard extends Command
{
    public const PATTERNS = ['%@hammamluxury.test', '%.test', '%@example.com', '%@example.test', '%demo%'];

    protected $signature = 'hl:demo-guard {--alert : Alerte e-mail en cas de détection}';

    protected $description = 'Vérifie qu’aucune donnée de démonstration (DemoSeeder, comptes .test) n’est présente ; échoue en production si c’est le cas';

    public function handle(): int
    {
        $findings = self::findings();
        if ($findings === []) {
            $this->info('Aucune donnée de démonstration détectée.');

            return self::SUCCESS;
        }

        foreach ($findings as $f) {
            $this->warn($f);
        }
        if (! app()->isProduction()) {
            $this->line('Environnement '.app()->environment().' : toléré.');

            return self::SUCCESS;
        }
        if ($this->option('alert')) {
            $this->call('hl:alert', ['subject' => '[HL] Données de démonstration détectées en production', 'body' => implode("\n", $findings)]);
        }

        return self::FAILURE;
    }

    /** @return list<string> */
    public static function findings(): array
    {
        $out = [];
        $users = User::query()->where(fn ($q) => collect(self::PATTERNS)->each(fn ($p) => $q->orWhere('email', 'like', $p)))->pluck('email');
        foreach ($users as $email) {
            $out[] = "Compte de démonstration : {$email}";
        }
        $spas = Spa::query()->where(fn ($q) => $q->where('email', 'like', '%.test')->orWhere('slug', 'like', '%demo%')->orWhere('name', 'like', '%démo%')->orWhere('name', 'like', '%demo%'))->pluck('slug');
        foreach ($spas as $slug) {
            $out[] = "Établissement de démonstration : {$slug}";
        }

        return $out;
    }
}
