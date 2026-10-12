<?php

namespace Tests\Engine;

use App\Models\Allocation;
use App\Models\Booking;
use App\Models\LedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Symfony\Component\Process\Process;

/** Vrais processus et connexions MariaDB ; les modèles sont chargés avant le départ de la course. */
class BookingStateConcurrencyTest extends BookingFlowTestCase
{
    use DatabaseTruncation;

    public function refreshDatabase(): void {}

    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();
        parent::tearDown();
    }

    /** @param array<int, string> $actions */
    private function race(Booking $booking, array $actions, ?CarbonImmutable $start = null, ?array $intent = null): array
    {
        $connection = config('database.default');
        $dbConfig = config("database.connections.$connection");
        $this->assertMatchesRegularExpression('/(?:^|[_-])test(?:ing)?(?:[_-]|$)/i', $dbConfig['database']);
        $this->assertContains($dbConfig['host'], ['127.0.0.1', 'localhost', '::1']);
        $this->assertTrue($this->engine->lock($this->spa), 'Le parent retient le verrou avant de charger les enfants.');
        $processes = [];

        try {
            foreach ($actions as $action) {
                $command = [PHP_BINARY, base_path('tests/Support/booking-state-racer.php'), (string) $booking->id, $action];
                if ($start) {
                    $command[] = $start->toDateTimeString();
                }
                $process = new Process($command, base_path(), [
                    'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'),
                    'DB_CONNECTION' => $connection, 'DB_HOST' => $dbConfig['host'],
                    'DB_PORT' => (string) ($dbConfig['port'] ?? 3306),
                    'DB_DATABASE' => $dbConfig['database'], 'DB_USERNAME' => $dbConfig['username'],
                    'DB_PASSWORD' => $dbConfig['password'], 'DB_URL' => '',
                    'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array',
                    'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array',
                    'HL_BOOKING_TEST_NOW' => CarbonImmutable::now()->toDateTimeString(),
                    'HL_BOOKING_TEST_INTENT' => $intent ? json_encode($intent, JSON_THROW_ON_ERROR) : '',
                ]);
                $process->setTimeout(60)->start();
                $processes[] = $process;
            }

            $deadline = microtime(true) + 15;
            do {
                $ready = array_filter($processes, fn (Process $process) => str_contains($process->getOutput(), '"ready":true'));
                if (count($ready) === count($processes)) {
                    break;
                }
                foreach ($processes as $process) {
                    if (! $process->isRunning()) {
                        $this->fail('Un enfant s’est arrêté avant le départ : '.$process->getErrorOutput().$process->getOutput());
                    }
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertCount(count($processes), $ready, 'Chaque processus doit avoir chargé son modèle avant le départ.');
        } catch (\Throwable $exception) {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            throw $exception;
        } finally {
            $this->engine->unlock($this->spa);
        }

        $results = [];
        try {
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
                $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));
                $result = json_decode(end($lines), true);
                $this->assertIsArray($result, $process->getOutput().$process->getErrorOutput());
                $results[] = $result;
            }
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
        }

        return $results;
    }

    private function waiting(): Booking
    {
        return $this->bookings->book($this->spa, $this->at('10:00'), [
            ['treatment' => $this->m->id, 'party' => 1],
        ], $this->customer);
    }

    public function test_accept_and_decline_make_one_atomic_decision(): void
    {
        $booking = $this->waiting();
        $results = $this->race($booking, ['accept', 'decline', 'accept', 'decline', 'accept', 'decline']);

        $this->assertCount(1, array_filter($results, fn (array $result) => $result['ok']), json_encode($results));
        $fresh = $booking->fresh();
        $this->assertContains($fresh->status, ['confirmed', 'declined']);
        $this->assertSame($fresh->status === 'confirmed' ? 1 : 0, count($this->activeAllocations($fresh)));
        $this->assertSame(1, $fresh->events()->whereIn('type', ['confirmed', 'declined'])->count());
        $this->assertSame($fresh->status === 'declined' ? 1 : 0, $fresh->declines()->count());
    }

    public function test_accept_and_cancel_never_leave_a_confirmed_booking_without_capacity(): void
    {
        $booking = $this->waiting();
        $results = $this->race($booking, ['accept', 'cancel', 'accept', 'cancel', 'accept', 'cancel']);

        $this->assertCount(1, array_filter($results, fn (array $result) => $result['ok'] && $result['action'] === 'cancel'));
        $this->assertSame('cancelled', $booking->fresh()->status);
        $this->assertSame([], $this->activeAllocations($booking));
        $this->assertSame(1, $booking->events()->where('type', 'cancelled')->count());
        $this->assertSame(0, LedgerEntry::where('booking_id', $booking->id)->count());
    }

    public function test_expired_request_cannot_be_accepted_during_worker_execution(): void
    {
        $booking = $this->waiting();
        Booking::whereKey($booking->id)->update(['expires_at' => now()->subMinute()]);
        $results = $this->race($booking, ['accept', 'expire', 'accept', 'expire']);

        $this->assertCount(0, array_filter($results, fn (array $result) => $result['ok'] && $result['action'] === 'accept'));
        $this->assertCount(1, array_filter($results, fn (array $result) => $result['ok'] && $result['action'] === 'expire'));
        $this->assertSame('expired', $booking->fresh()->status);
        $this->assertSame([], $this->activeAllocations($booking));
        $this->assertSame(1, $booking->events()->where('type', 'expired')->count());
    }

    public function test_parallel_closing_records_the_service_and_commission_once(): void
    {
        $booking = $this->waiting();
        $this->bookings->accept($booking);
        $this->travelTo($booking->end_at->addMinute()->toMutable());
        $results = $this->race($booking, array_fill(0, 6, 'complete'));

        $this->assertCount(1, array_filter($results, fn (array $result) => $result['ok']), json_encode($results));
        $this->assertSame('completed', $booking->fresh()->status);
        $this->assertSame(1, $booking->events()->where('type', 'completed')->count());
        $this->assertSame(1, LedgerEntry::where('booking_id', $booking->id)->where('type', 'commission')->count());
        $this->assertSame(60.0, (float) LedgerEntry::where('booking_id', $booking->id)->sum('amount'));
    }

    public function test_reschedule_racing_with_cancellation_cannot_recreate_allocations(): void
    {
        $booking = $this->waiting();
        $this->bookings->accept($booking);
        $results = $this->race($booking, ['reschedule', 'cancel', 'reschedule', 'cancel'], $this->at('17:00'));

        $this->assertCount(1, array_filter($results, fn (array $result) => $result['ok'] && $result['action'] === 'cancel'));
        $this->assertSame('cancelled', $booking->fresh()->status);
        $this->assertSame([], $this->activeAllocations($booking));
        $this->assertSame(1, $booking->events()->where('type', 'cancelled')->count());
    }

    public function test_reschedule_and_new_booking_compete_for_the_same_single_room(): void
    {
        $booking = $this->bookings->book($this->spa, $this->at('10:00'), [
            ['treatment' => $this->hs->id, 'party' => 1],
        ], $this->customer, 'confirmed');
        $results = $this->race($booking, ['reschedule', 'book'], $this->at('16:00'));

        $this->assertCount(1, array_filter($results, fn (array $result) => $result['ok']), json_encode($results));
        $roomType = $this->spa->resourceTypes()->where('slug', 'soin')->firstOrFail();
        $this->assertSame(1, Allocation::where('spa_id', $this->spa->id)
            ->where('resource_type_id', $roomType->id)->where('status', 'active')
            ->where('start_at', '<', $this->at('17:35'))->where('end_at', '>', $this->at('16:45'))->count());
        $this->assertSame('confirmed', $booking->fresh()->status);
        $this->assertCount(2, $this->activeAllocations($booking));
    }

    public function test_parallel_retries_of_one_intent_create_exactly_one_booking(): void
    {
        $anchor = $this->waiting();
        $prepared = $this->intent('16:00', ['treatment' => $this->m->id, 'party' => 1]);
        $intent = [
            'token' => $prepared['intent']['token'],
            'payload' => $this->intents->resolve($prepared['intent']['token'], $this->spa),
            'customer' => $this->customer,
        ];
        $results = $this->race($anchor, array_fill(0, 6, 'confirm'), intent: $intent);

        $this->assertCount(6, array_filter($results, fn (array $result) => $result['ok']), json_encode($results));
        $ids = array_unique(array_column($results, 'id'));
        $this->assertCount(1, $ids, 'Tous les rejouements doivent renvoyer le même identifiant.');
        $booking = Booking::where('intent_id', $prepared['intent']['id'])->sole();
        $this->assertSame($booking->id, array_values($ids)[0]);
        $this->assertCount(1, $this->activeAllocations($booking));
        $this->assertSame(1, $booking->participants()->count());
        $this->assertSame(1, $booking->events()->where('type', 'created')->count());
        $this->assertSame(2, Booking::where('spa_id', $this->spa->id)->count(), 'La réservation initiale et une seule nouvelle réservation.');
    }
}
