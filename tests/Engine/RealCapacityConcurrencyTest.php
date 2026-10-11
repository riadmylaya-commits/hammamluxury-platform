<?php

namespace Tests\Engine;

use App\Models\Allocation;
use App\Models\Booking;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Symfony\Component\Process\Process;

/** Concurrence réelle sur les praticiens : 3 cabines mais 2 praticiennes → 2 gagnants. */
class RealCapacityConcurrencyTest extends RealCapacityTestCase
{
    use DatabaseTruncation;

    /** Les données doivent être visibles des autres connexions : pas de transaction englobante. */
    public function refreshDatabase(): void {}

    /** Les lignes validées par les processus fils survivraient aux autres tests : on nettoie. */
    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();
        parent::tearDown();
    }

    public function test_concurrent_therapist_requests_exactly_two_win(): void
    {
        $procs = [];
        for ($i = 0; $i < 6; $i++) {
            $p = new Process([PHP_BINARY, 'artisan', 'hl:book', $this->spa->slug, "{$this->day} 16:00", 'massage-60', '--party=1', "--name=P$i"], base_path(), [
                'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mariadb', 'DB_DATABASE' => env('DB_DATABASE'), 'DB_USERNAME' => env('DB_USERNAME'), 'DB_PASSWORD' => env('DB_PASSWORD'),
                'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            ]);
            $p->setTimeout(60)->start();
            $procs[] = $p;
        }
        $res = [];
        foreach ($procs as $p) {
            $p->wait();
            $decoded = json_decode(trim($p->getOutput()), true);
            $this->assertIsArray($decoded, 'Sortie processus invalide : '.$p->getOutput().$p->getErrorOutput());
            $res[] = $decoded;
        }
        $this->assertCount(2, array_filter($res, fn ($r) => $r['ok']), '6 demandes simultanées / 3 cabines mais 2 praticiennes : exactement 2 acceptées — '.json_encode($res));
        $this->assertSame(2, Booking::where('spa_id', $this->spa->id)->count());
        $this->assertSame(4, Allocation::where('spa_id', $this->spa->id)->where('status', 'active')->count(), '2 × (cabine + praticienne)');
    }
}
