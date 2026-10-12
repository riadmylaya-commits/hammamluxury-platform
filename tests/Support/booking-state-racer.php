<?php

/** Processus de concurrence réservé aux tests, jamais exposé comme commande applicative. */
use App\Domain\Booking\BookingException;
use App\Domain\Booking\BookingService;
use App\Models\Booking;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

require dirname(__DIR__, 2).'/vendor/autoload.php';

// Vérifications avant bootstrap et avant toute lecture ou écriture métier.
if (getenv('APP_ENV') !== 'testing' || getenv('DB_URL')) {
    fwrite(STDERR, 'Ce processus exige APP_ENV=testing et une connexion DB explicite.'.PHP_EOL);
    exit(2);
}

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = config('database.default');
$database = (string) config("database.connections.$connection.database");
$host = (string) config("database.connections.$connection.host");
if (! $app->environment('testing')
    || ! preg_match('/(?:^|[_-])test(?:ing)?(?:[_-]|$)/i', $database)
    || ! in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, 'Refus : seule une base locale portant un nom de test est autorisée.'.PHP_EOL);
    exit(2);
}

config(['hl.lock_timeout_seconds' => 30]);
if ($now = getenv('HL_BOOKING_TEST_NOW')) {
    Carbon::setTestNow(Carbon::parse($now));
    CarbonImmutable::setTestNow(CarbonImmutable::parse($now));
}
Mail::fake();

$booking = Booking::query()->findOrFail((int) ($argv[1] ?? 0));
$action = $argv[2] ?? '';
$start = isset($argv[3]) ? CarbonImmutable::parse($argv[3]) : null;
$service = $app->make(BookingService::class);
$intent = null;
if ($action === 'confirm') {
    $intent = json_decode((string) getenv('HL_BOOKING_TEST_INTENT'), true, 512, JSON_THROW_ON_ERROR);
    Cache::put('hl:intent:'.$intent['payload']['id'], $intent['payload'], 3600);
}

// Le parent retient le verrou du spa jusqu'à ce que tous les enfants aient chargé
// la même photographie. Chaque opération doit ensuite relire l'état sous verrou.
fwrite(STDOUT, json_encode(['ready' => true], JSON_THROW_ON_ERROR).PHP_EOL);
fflush(STDOUT);

try {
    $result = match ($action) {
        'accept' => $service->accept($booking),
        'decline' => $service->decline($booking, 'partner', 'closed'),
        'cancel' => $service->cancel($booking, 'client'),
        'complete' => $service->complete($booking),
        'confirm' => $service->confirmIntent($booking->spa, $intent['token'], $intent['customer']),
        'reschedule' => $service->reschedule($booking, $start),
        'book' => $service->book($booking->spa, $start, $booking->quote['items'], [
            'first_name' => 'Concurrent', 'last_name' => 'Client',
            'email' => 'concurrent@example.test', 'phone' => '0611111111',
        ], 'confirmed'),
        'expire' => $service->expireWaiting(),
        default => throw new RuntimeException('Action de test inconnue.'),
    };
    $ok = $action !== 'expire' || in_array($booking->id, $result, true);
    fwrite(STDOUT, json_encode([
        'ok' => $ok, 'action' => $action,
        'id' => $result instanceof Booking ? $result->id : $booking->id,
        'reason' => $ok ? '' : 'already_processed',
    ], JSON_THROW_ON_ERROR).PHP_EOL);
} catch (BookingException $exception) {
    fwrite(STDOUT, json_encode([
        'ok' => false, 'action' => $action, 'reason' => $exception->reason,
        'status' => $exception->status, 'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR).PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(1);
}
