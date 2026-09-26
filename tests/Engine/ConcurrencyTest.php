<?php

namespace Tests\Engine;

use App\Models\Booking;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Symfony\Component\Process\Process;

/**
 * Concurrence réelle : N processus PHP distincts tentent le même créneau au même instant.
 * Le verrou GET_LOCK par établissement doit garantir qu'exactement la capacité restante est accordée.
 */
class ConcurrencyTest extends EngineTestCase
{
    use DatabaseTruncation;

    /** Les données doivent être visibles des autres connexions : pas de transaction englobante. */
    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        $this->spa('hl-test-concurrency', 'HL TEST Concurrence');
        $h = $this->type('hammam', 'Hammam');
        $m = $this->type('massage', 'Cabine de massage', 'unit');
        $this->resource($h, 'Hammam', 6);
        $this->resource($m, 'Cabine 1', 1);
        $this->resource($m, 'Cabine 2', 1);
        $this->treatment('hammam', 'Hammam', [['type' => 'hammam', 'duration' => 45]], 6, ['price_solo' => 150]);
        $this->treatment('massage', 'Massage', [['type' => 'massage', 'duration' => 60]], 4, ['price_solo' => 400]);
    }

    /** Les lignes validées par les processus fils survivraient aux autres tests : on nettoie. */
    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();
        parent::tearDown();
    }

    /** @return array<int, array> résultats JSON de chaque processus */
    private function race(int $n, string $time, string $treatment, int $party): array
    {
        $procs = [];
        for ($i = 0; $i < $n; $i++) {
            $p = new Process([PHP_BINARY, 'artisan', 'hl:book', $this->spa->slug, "{$this->day} $time", $treatment, "--party=$party", "--name=P$i"], base_path(), [
                'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mariadb', 'DB_DATABASE' => env('DB_DATABASE'), 'DB_USERNAME' => env('DB_USERNAME'), 'DB_PASSWORD' => env('DB_PASSWORD'),
                'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            ]);
            $p->setTimeout(60)->start();
            $procs[] = $p;
        }
        $out = [];
        foreach ($procs as $p) {
            $p->wait();
            $decoded = json_decode(trim($p->getOutput()), true);
            $this->assertIsArray($decoded, 'Sortie processus invalide : '.$p->getOutput().$p->getErrorOutput());
            $out[] = $decoded;
        }

        return $out;
    }

    public function test_two_cabins_six_racers_exactly_two_win(): void
    {
        $res = $this->race(6, '16:00', 'massage', 1);
        $ok = array_filter($res, fn ($r) => $r['ok']);
        $this->assertCount(2, $ok, 'Concurrence 6 processus / 2 cabines : exactement 2 acceptés — '.json_encode($res));
        $this->assertSame(2, Booking::where('spa_id', $this->spa->id)->where('status', 'confirmed')->count(), 'Base : 2 réservations confirmées');
        $this->assertSame(2, Booking::where('spa_id', $this->spa->id)->count(), 'Aucune réservation refusée n’a été insérée');
        foreach (array_filter($res, fn ($r) => ! $r['ok']) as $r) {
            $this->assertSame('unavailable', $r['reason'], 'Refus motivés par la capacité, pas par le verrou : '.$r['message']);
        }
    }

    public function test_pool_of_six_five_couples_exactly_three_win(): void
    {
        $res = $this->race(5, '10:00', 'hammam', 2);
        $ok = array_filter($res, fn ($r) => $r['ok']);
        $this->assertCount(3, $ok, 'Concurrence 5 couples / hammam 6 places : exactement 3 acceptés — '.json_encode($res));
        $free = $this->engine->freeCapacity($this->spa->fresh(), $this->at('10:00'), $this->at('10:45'));
        $this->assertSame(0, $free['hammam'], 'Hammam complet après la course');
        $this->assertSame(6, (int) Booking::where('spa_id', $this->spa->id)->sum('party'), 'Somme des participants = capacité exacte');
    }
}
