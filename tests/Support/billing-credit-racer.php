<?php

/** Processus de concurrence réservé aux tests (avoirs et crédits F4), jamais exposé comme commande applicative. */
use App\Domain\Billing\BillingGate;
use App\Domain\Billing\BillingSettings;
use App\Domain\Billing\CreditNoteException;
use App\Domain\Billing\CreditNoteService;
use App\Domain\Billing\PartnerCreditService;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\PartnerCredit;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

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
Queue::fake();
if (getenv('HL_BILLING_TEST_LOTS_DELIVERED') === '1') {
    // Même levée de verrou que BillingF4Test : les lots sont réputés livrés pour ce processus de test uniquement.
    $app->bind(BillingGate::class, fn ($app, array $params) => new class($params['settings'] ?? $app->make(BillingSettings::class)) extends BillingGate
    {
        protected function issuanceLotsDelivered(): bool
        {
            return true;
        }
    });
}

// argv : action (issue|reverse|apply|refund), id (avoir ou crédit), [facture cible], [montant], [clé]
$action = $argv[1] ?? '';
$id = (int) ($argv[2] ?? 0);
$admin = User::where('role', 'admin')->orderBy('id')->firstOrFail();
$notes = $app->make(CreditNoteService::class);
$credits = $app->make(PartnerCreditService::class);
$subject = in_array($action, ['issue', 'reverse'], true) ? CreditNote::findOrFail($id) : PartnerCredit::findOrFail($id);
$target = isset($argv[3]) && $argv[3] !== '' ? Invoice::findOrFail((int) $argv[3]) : null;

fwrite(STDOUT, json_encode(['ready' => true], JSON_THROW_ON_ERROR).PHP_EOL);
fflush(STDOUT);
$startAt = (float) getenv('HL_START_AT');
while (microtime(true) < $startAt) {
    usleep(200);
}

try {
    $result = match ($action) {
        'issue' => ['id' => ($n = $notes->issue($subject, $admin))->id, 'number' => $n->number],
        'reverse' => ['id' => ($n = $notes->reverse($subject, $admin, 'course de test'))->id, 'number' => $n->number],
        'apply' => ['id' => ($a = $credits->apply($subject, $target, $admin, (float) $argv[4], $argv[5] ?? uniqid('apply-', true)))->id, 'amount' => $a->amount, 'remaining' => $a->credit_remaining_after],
        'refund' => ['id' => ($a = $credits->refund($subject, $admin, ['amount' => (float) $argv[4], 'refund_on' => CarbonImmutable::now(config('hl.timezone'))->toDateString(),
            'method' => 'bank_transfer', 'reference' => 'race'], $argv[5] ?? uniqid('refund-', true)))->id, 'amount' => $a->amount, 'remaining' => $a->credit_remaining_after],
        default => throw new RuntimeException('Action de test inconnue.'),
    };
    fwrite(STDOUT, json_encode(['ok' => true, 'action' => $action] + $result, JSON_THROW_ON_ERROR).PHP_EOL);
} catch (CreditNoteException $exception) {
    fwrite(STDOUT, json_encode(['ok' => false, 'action' => $action, 'reason' => $exception->reason, 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR).PHP_EOL);
}
