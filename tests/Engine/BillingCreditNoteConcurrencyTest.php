<?php

namespace Tests\Engine;

use App\Domain\Billing\BillingGate;
use App\Domain\Billing\BillingSettings;
use App\Domain\Billing\CreditNoteService;
use App\Domain\Billing\InvoiceDrafter;
use App\Domain\Billing\InvoiceIssuer;
use App\Domain\Billing\PartnerCreditService;
use App\Domain\Billing\PaymentRecorder;
use App\Jobs\GenerateCreditNotePdf;
use App\Jobs\GenerateInvoicePdf;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\PartnerCredit;
use App\Models\PartnerCreditApplication;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/** F4 : émissions d’avoir, annulations et consommations de crédit concurrentes, vrais processus et connexions MariaDB ; un seul numéro, un seul usage. */
class BillingCreditNoteConcurrencyTest extends BookingFlowTestCase
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
        Queue::fake([GenerateInvoicePdf::class, GenerateCreditNotePdf::class]);
        $draft = app(InvoiceDrafter::class)->draft($this->spa->partner->fresh(), $periodStart->format('Y-m'));
        $this->assertNotNull($draft);

        return app(InvoiceIssuer::class)->issue($draft, $this->admin);
    }

    /** @param  list<list<string>>  $jobs  chaque entrée = [action, montant|payment_id, méthode, clé] */
    private function race(array $jobs): array
    {
        $connection = config('database.default');
        $dbConfig = config("database.connections.$connection");
        $this->assertMatchesRegularExpression('/(?:^|[_-])test(?:ing)?(?:[_-]|$)/i', $dbConfig['database']);
        $this->assertContains($dbConfig['host'], ['127.0.0.1', 'localhost', '::1']);
        $startAt = microtime(true) + 3;
        $processes = [];
        foreach ($jobs as $job) {
            $command = array_merge([PHP_BINARY, base_path('tests/Support/billing-credit-racer.php')], $job);
            $process = new Process($command, base_path(), [
                'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'),
                'DB_CONNECTION' => $connection, 'DB_HOST' => $dbConfig['host'], 'DB_PORT' => (string) ($dbConfig['port'] ?? 3306),
                'DB_DATABASE' => $dbConfig['database'], 'DB_USERNAME' => $dbConfig['username'], 'DB_PASSWORD' => $dbConfig['password'], 'DB_URL' => '',
                'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array',
                'HL_BOOKING_TEST_NOW' => CarbonImmutable::now()->toDateTimeString(), 'HL_START_AT' => sprintf('%.4F', $startAt),
                'HL_BILLING_ACCEPTANCE_REF' => 'Recette test', 'HL_BILLING_OWNER_AUTHORIZATION_REF' => 'Accord test', 'HL_BILLING_TEST_LOTS_DELIVERED' => '1',
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

    private function correctionNote(Invoice $invoice): CreditNote
    {
        $booking = $invoice->lines()->first()->booking;
        $this->bookings->setPaymentStatus($booking, 'admin', 'refunded');
        $this->artisan('hl:billing-mark-legacy', ['--apply' => true])->assertSuccessful();
        $entry = LedgerEntry::where('booking_id', $booking->id)->orderByDesc('id')->firstOrFail();
        $this->assertLessThan(0, $entry->amount);

        return app(CreditNoteService::class)->draft($invoice, $this->admin, [$entry->id], 'course');
    }

    public function test_parallel_issues_of_one_draft_produce_a_single_numbered_credit_note(): void
    {
        $invoice = $this->issuedInvoice();
        $note = $this->correctionNote($invoice);
        $results = $this->race([['issue', (string) $note->id], ['issue', (string) $note->id], ['issue', (string) $note->id]]);
        $ok = array_filter($results, fn ($r) => $r['ok']);
        $this->assertCount(1, $ok, json_encode($results));
        foreach (array_filter($results, fn ($r) => ! $r['ok']) as $refused) {
            $this->assertSame('already_issued', $refused['reason']);
        }
        $this->assertSame(1, CreditNote::count());
        $this->assertSame('HL-A-'.CarbonImmutable::now()->year.'-00001', CreditNote::sole()->number);
        $invoice->refresh();
        $this->assertEqualsWithDelta(CreditNote::sole()->total_ttc, $invoice->credited_amount, 0.001);
        $this->assertEqualsWithDelta((float) $invoice->total_ttc - $invoice->credited_amount, $invoice->balance, 0.001);
    }

    public function test_parallel_reversals_of_one_credit_note_produce_a_single_reversal(): void
    {
        $invoice = $this->issuedInvoice();
        $issued = app(CreditNoteService::class)->issue($this->correctionNote($invoice), $this->admin);
        $results = $this->race([['reverse', (string) $issued->id], ['reverse', (string) $issued->id], ['reverse', (string) $issued->id]]);
        $this->assertCount(1, array_filter($results, fn ($r) => $r['ok']), json_encode($results));
        $this->assertSame(2, CreditNote::count());
        $this->assertSame(1, CreditNote::whereNotNull('reverses_credit_note_id')->count());
        $this->assertSame(0.0, (float) $invoice->fresh()->credited_amount);
        $this->assertEqualsWithDelta((float) $invoice->total_ttc, $invoice->fresh()->balance, 0.001);
    }

    public function test_parallel_consumptions_of_one_credit_never_exceed_it(): void
    {
        $invoice = $this->issuedInvoice();
        app(PaymentRecorder::class)->record($invoice, $this->admin, ['amount' => (float) $invoice->total_ttc + 30, 'paid_on' => CarbonImmutable::now()->toDateString(),
            'method' => 'bank_transfer', 'idempotency_key' => (string) Str::uuid()]);
        $credit = app(PartnerCreditService::class)->fromOverpayment($invoice->overpayments()->sole(), $this->admin);
        $this->assertEqualsWithDelta(30, $credit->amount, 0.001);
        $jobs = [];
        for ($i = 0; $i < 5; $i++) {
            $jobs[] = ['refund', (string) $credit->id, '', '10', 'race-refund-'.$i];
        }
        $results = $this->race($jobs);
        $ok = array_filter($results, fn ($r) => $r['ok']);
        $this->assertCount(3, $ok, json_encode($results));
        foreach (array_filter($results, fn ($r) => ! $r['ok']) as $refused) {
            $this->assertContains($refused['reason'], ['exceeds_credit', 'credit_closed']);
        }
        $credit->refresh();
        $this->assertEqualsWithDelta(30, $credit->refunded_amount, 0.001);
        $this->assertSame(0.0, $credit->remaining());
        $this->assertSame(PartnerCredit::STATUS_REFUNDED, $credit->status);
        $this->assertSame(3, PartnerCreditApplication::count());
        $this->assertSame([20.0, 10.0, 0.0], PartnerCreditApplication::orderBy('id')->pluck('credit_remaining_after')->map(fn ($v) => (float) $v)->all());
        $this->assertSame('refunded', $invoice->overpayments()->sole()->status);
    }

    public function test_same_key_sent_in_parallel_refunds_once(): void
    {
        $invoice = $this->issuedInvoice();
        app(PaymentRecorder::class)->record($invoice, $this->admin, ['amount' => (float) $invoice->total_ttc + 30, 'paid_on' => CarbonImmutable::now()->toDateString(),
            'method' => 'bank_transfer', 'idempotency_key' => (string) Str::uuid()]);
        $credit = app(PartnerCreditService::class)->fromOverpayment($invoice->overpayments()->sole(), $this->admin);
        $results = $this->race([['refund', (string) $credit->id, '', '10', 'same'], ['refund', (string) $credit->id, '', '10', 'same'], ['refund', (string) $credit->id, '', '10', 'same']]);
        $this->assertCount(3, array_filter($results, fn ($r) => $r['ok']), json_encode($results));
        $this->assertCount(1, array_unique(array_column($results, 'id')));
        $this->assertSame(1, PartnerCreditApplication::count());
        $this->assertEqualsWithDelta(20, $credit->fresh()->remaining(), 0.001);
    }
}
