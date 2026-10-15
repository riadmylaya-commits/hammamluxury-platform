<?php

namespace Tests\Engine;

use App\Domain\Billing\BillingGate;
use App\Domain\Billing\BillingSettings;
use App\Domain\Billing\InvoiceDrafter;
use App\Domain\Billing\InvoiceIssuer;
use App\Jobs\GenerateInvoicePdf;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\LedgerEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;

/** F3 : paiements concurrents sur une même facture, vrais processus et connexions MariaDB ; solde exact, jamais négatif, reçus uniques. */
class BillingPaymentConcurrencyTest extends BookingFlowTestCase
{
    use DatabaseTruncation;

    protected array $exceptTables = ['cities', 'categories', 'amenities'];

    private User $admin;

    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'secret-test', 'role' => 'admin', 'email_verified_at' => now()]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        $this->truncateTablesForAllConnections();
        parent::tearDown();
    }

    private function issuedInvoice(): Invoice
    {
        $result = $this->submit($this->intent('10:00', ['treatment' => $this->m->id, 'party' => 1]));
        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $booking = $result['booking'];
        $this->bookings->accept($booking, 'partner');
        $this->travelTo($booking->end_at->addHours(5)->toMutable());
        $this->bookings->complete($booking, 'admin');
        $this->travelBack();
        $this->actingAs($this->admin);
        $s = app(BillingSettings::class);
        $s->set('start_period', CarbonImmutable::now()->subMonthNoOverflow()->format('Y-m'));
        $this->artisan('hl:billing-mark-legacy', ['--apply' => true])->assertSuccessful();
        $this->app->bind(BillingGate::class, fn ($app, array $params) => new class($params['settings'] ?? $app->make(BillingSettings::class)) extends BillingGate
        {
            protected function issuanceLotsDelivered(): bool
            {
                return true;
            }
        });
        $s->set('vat_mode', 'none');
        $s->set('accountant_validated_at', '2026-12-15');
        foreach (BillingSettings::ISSUER_REQUIRED as $f) {
            $s->set('issuer.'.$f, $f === 'ice' ? '001234567000089' : 'HL '.$f);
        }
        $this->spa->partner->update(['legal_form' => 'SARL', 'tax_id' => 'IF123', 'registry_number' => 'RC456', 'ice' => '001234567000089',
            'billing_address' => '1 rue Test', 'billing_city' => 'Marrakech', 'billing_email' => 'compta@example.test']);
        config(['hl.billing.acceptance_ref' => 'Recette staging', 'hl.billing.owner_authorization_ref' => 'Accord écrit']);
        $s->set('enabled', true);
        auth()->logout();
        $periodStart = CarbonImmutable::parse($this->day, config('hl.timezone'))->startOfMonth();
        $this->travelTo($periodStart->addMonth()->day(6)->setTime(10, 0)->toMutable());
        Queue::fake([GenerateInvoicePdf::class]);
        $draft = app(InvoiceDrafter::class)->draft($this->spa->partner->fresh(), $periodStart->format('Y-m'));
        $this->assertNotNull($draft);

        return app(InvoiceIssuer::class)->issue($draft, $this->admin);
    }

    /** @param  list<list<string>>  $jobs  chaque entrée = [action, montant|payment_id, méthode, clé] */
    private function race(Invoice $invoice, array $jobs): array
    {
        $connection = config('database.default');
        $dbConfig = config("database.connections.$connection");
        $this->assertMatchesRegularExpression('/(?:^|[_-])test(?:ing)?(?:[_-]|$)/i', $dbConfig['database']);
        $this->assertContains($dbConfig['host'], ['127.0.0.1', 'localhost', '::1']);
        $startAt = microtime(true) + 3;
        $processes = [];
        foreach ($jobs as $job) {
            $command = array_merge([PHP_BINARY, base_path('tests/Support/billing-payment-racer.php'), (string) $invoice->id], $job);
            $process = new Process($command, base_path(), [
                'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'),
                'DB_CONNECTION' => $connection, 'DB_HOST' => $dbConfig['host'], 'DB_PORT' => (string) ($dbConfig['port'] ?? 3306),
                'DB_DATABASE' => $dbConfig['database'], 'DB_USERNAME' => $dbConfig['username'], 'DB_PASSWORD' => $dbConfig['password'], 'DB_URL' => '',
                'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array',
                'HL_BOOKING_TEST_NOW' => CarbonImmutable::now()->toDateTimeString(), 'HL_START_AT' => sprintf('%.4F', $startAt),
            ]);
            $process->setTimeout(60)->start();
            $processes[] = $process;
        }
        $results = [];
        try {
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
                $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));
                $this->assertStringContainsString('"ready":true', $lines[0] ?? '');
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

    public function test_parallel_payments_keep_an_exact_balance_that_never_goes_negative(): void
    {
        $invoice = $this->issuedInvoice();
        $total = (float) $invoice->total_ttc;
        $ledgerHash = LedgerEntry::orderBy('id')->get()->map(fn ($e) => $e->id.':'.$e->amount.':'.$e->type)->implode('|');
        $part = round($total / 4, 2);
        $jobs = [];
        for ($i = 0; $i < 6; $i++) {
            $jobs[] = ['pay', (string) $part, 'bank_transfer', 'race-pay-'.$i];
        }
        $results = $this->race($invoice, $jobs);
        $ok = array_filter($results, fn ($r) => $r['ok']);
        $this->assertCount(4, $ok, json_encode($results));
        foreach (array_filter($results, fn ($r) => ! $r['ok']) as $refused) {
            $this->assertSame('invoice_closed', $refused['reason']);
        }
        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertEqualsWithDelta($total, $invoice->paid_amount, 0.001);
        $this->assertSame(0.0, (float) $invoice->balance);
        $this->assertSame(4, InvoicePayment::count());
        $this->assertSame(round($total, 2), round((float) InvoicePayment::sum('amount'), 2));
        $balances = InvoicePayment::orderBy('id')->pluck('balance_after')->map(fn ($b) => (float) $b)->all();
        $this->assertSame([round($total - $part, 2), round($total - 2 * $part, 2), round($total - 3 * $part, 2), 0.0], $balances);
        $this->assertSame($ledgerHash, LedgerEntry::orderBy('id')->get()->map(fn ($e) => $e->id.':'.$e->amount.':'.$e->type)->implode('|'));
    }

    public function test_same_operation_key_sent_in_parallel_records_one_payment(): void
    {
        $invoice = $this->issuedInvoice();
        $results = $this->race($invoice, [['pay', '10', 'bank_transfer', 'same-key'], ['pay', '10', 'bank_transfer', 'same-key'], ['pay', '10', 'bank_transfer', 'same-key']]);
        $this->assertCount(3, array_filter($results, fn ($r) => $r['ok']));
        $this->assertCount(1, array_unique(array_column($results, 'id')));
        $this->assertSame(1, InvoicePayment::count());
        $this->assertEqualsWithDelta(10.0, $invoice->fresh()->paid_amount, 0.001);
    }

    public function test_parallel_cash_payments_get_distinct_receipt_numbers_and_respect_the_limit(): void
    {
        $invoice = $this->issuedInvoice();
        $this->actingAs($this->admin);
        app(BillingSettings::class)->set('cash_limit', 25);
        auth()->logout();
        $results = $this->race($invoice, [['pay', '10', 'cash', 'cash-1'], ['pay', '10', 'cash', 'cash-2'], ['pay', '10', 'cash', 'cash-3']]);
        $ok = array_values(array_filter($results, fn ($r) => $r['ok']));
        $this->assertCount(2, $ok, json_encode($results));
        $this->assertSame('cash_limit_exceeded', collect($results)->firstWhere('ok', false)['reason']);
        $receipts = InvoicePayment::cash()->pluck('receipt_number')->all();
        $this->assertCount(2, array_unique($receipts));
        $year = CarbonImmutable::now(config('hl.timezone'))->year;
        sort($receipts);
        $this->assertSame(["HL-R-$year-00001", "HL-R-$year-00002"], $receipts);
        $this->assertEqualsWithDelta(20.0, $invoice->fresh()->paid_amount, 0.001);
    }

    public function test_parallel_reversals_of_one_payment_produce_a_single_reversal(): void
    {
        $invoice = $this->issuedInvoice();
        [$first] = $this->race($invoice, [['pay', '50', 'bank_transfer', 'to-reverse']]);
        $this->assertTrue($first['ok']);
        $results = $this->race($invoice, [['reverse', (string) $first['id'], '', 'rev-a'], ['reverse', (string) $first['id'], '', 'rev-b'], ['reverse', (string) $first['id'], '', 'rev-c']]);
        $this->assertCount(1, array_filter($results, fn ($r) => $r['ok']), json_encode($results));
        foreach (array_filter($results, fn ($r) => ! $r['ok']) as $refused) {
            $this->assertSame('already_reversed', $refused['reason']);
        }
        $this->assertSame(2, InvoicePayment::count());
        $invoice->refresh();
        $this->assertSame(0.0, (float) $invoice->paid_amount);
        $this->assertSame(Invoice::STATUS_ISSUED, $invoice->status);
        $this->assertEqualsWithDelta((float) $invoice->total_ttc, $invoice->balance, 0.001);
    }
}
