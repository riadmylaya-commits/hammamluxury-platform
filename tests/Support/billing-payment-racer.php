<?php

/** Processus de concurrence réservé aux tests (paiements F3), jamais exposé comme commande applicative. */
use App\Domain\Billing\PaymentException;
use App\Domain\Billing\PaymentRecorder;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Mail;

require dirname(__DIR__, 2).'/vendor/autoload.php';

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
if ($now = getenv('HL_BOOKING_TEST_NOW')) {
    Carbon::setTestNow(Carbon::parse($now));
    CarbonImmutable::setTestNow(CarbonImmutable::parse($now));
}
Mail::fake();

// argv : invoice_id, action (pay|reverse), amount|payment_id, method, idempotency_key
$invoice = Invoice::query()->findOrFail((int) ($argv[1] ?? 0));
$action = $argv[2] ?? 'pay';
$admin = User::where('role', 'admin')->orderBy('id')->firstOrFail();
$recorder = $app->make(PaymentRecorder::class);

// Barrière de départ : tous les enfants attendent la même micro-seconde (HL_START_AT) après avoir chargé la facture.
fwrite(STDOUT, json_encode(['ready' => true], JSON_THROW_ON_ERROR).PHP_EOL);
fflush(STDOUT);
$startAt = (float) getenv('HL_START_AT');
while (microtime(true) < $startAt) {
    usleep(200);
}

try {
    $payment = match ($action) {
        'pay' => $recorder->record($invoice, $admin, ['amount' => (float) $argv[3], 'paid_on' => CarbonImmutable::now(config('hl.timezone'))->toDateString(),
            'method' => $argv[4] ?? 'bank_transfer', 'reference' => 'race', 'idempotency_key' => $argv[5] ?? uniqid('race-', true)]),
        'reverse' => $recorder->reverse(InvoicePayment::findOrFail((int) $argv[3]), $admin, 'course de test', $argv[5] ?? uniqid('rev-', true)),
        default => throw new RuntimeException('Action de test inconnue.'),
    };
    fwrite(STDOUT, json_encode(['ok' => true, 'action' => $action, 'id' => $payment->id, 'amount' => $payment->amount, 'balance_after' => $payment->balance_after,
        'receipt' => $payment->receipt_number], JSON_THROW_ON_ERROR).PHP_EOL);
} catch (PaymentException $exception) {
    fwrite(STDOUT, json_encode(['ok' => false, 'action' => $action, 'reason' => $exception->reason, 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR).PHP_EOL);
}
