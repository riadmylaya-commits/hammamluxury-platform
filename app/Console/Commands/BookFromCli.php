<?php

namespace App\Console\Commands;

use App\Domain\Booking\BookingException;
use App\Domain\Booking\BookingService;
use App\Models\Spa;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** Réservation directe depuis la ligne de commande (support, scripts de charge, tests de concurrence). Sortie JSON. */
class BookFromCli extends Command
{
    protected $signature = 'hl:book {spa : id ou slug} {start : Y-m-d H:i} {treatment : id ou slug} {--party=1} {--extras=} {--status=confirmed} {--email=cli@example.test} {--name=CLI}';

    protected $description = 'Crée une réservation via le moteur de capacité (verrou + plan) et affiche le résultat en JSON';

    public function handle(BookingService $bookings): int
    {
        $spa = Spa::where('id', $this->argument('spa'))->orWhere('slug', $this->argument('spa'))->firstOrFail();
        $extras = array_values(array_filter(array_map('intval', explode(',', (string) $this->option('extras')))));
        try {
            $b = $bookings->book($spa, CarbonImmutable::parse($this->argument('start')), [['treatment' => $this->argument('treatment'), 'party' => (int) $this->option('party'), 'extras' => $extras]], ['first_name' => (string) $this->option('name'), 'last_name' => 'CLI', 'email' => (string) $this->option('email'), 'phone' => ''], (string) $this->option('status'));
            $this->line(json_encode(['ok' => true, 'id' => $b->id, 'reference' => $b->reference, 'total' => $b->total]));

            return self::SUCCESS;
        } catch (BookingException $e) {
            $this->line(json_encode(['ok' => false, 'reason' => $e->reason, 'message' => $e->getMessage()]));

            return self::FAILURE;
        }
    }
}
