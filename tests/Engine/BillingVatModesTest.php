<?php

namespace Tests\Engine;

use App\Domain\Billing\BillingGate;
use App\Domain\Billing\BillingSettings;
use App\Domain\Billing\CreditNoteService;
use App\Domain\Billing\InvoiceDrafter;
use App\Domain\Billing\InvoiceIssuer;
use App\Domain\Billing\PaymentRecorder;
use App\Jobs\GenerateCreditNotePdf;
use App\Jobs\GenerateInvoicePdf;
use App\Jobs\GenerateReceiptPdf;
use App\Models\Booking;
use App\Models\LedgerEntry;
use App\Models\Partner;
use App\Models\PartnerCredit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

/** Régimes de TVA (aucune, HT + TVA, TVA incluse) : montants HT / TVA / TTC cohérents sur la facture, l'avoir et le crédit partenaire. */
class BillingVatModesTest extends BookingFlowTestCase
{
    private User $admin;

    private string $period;

    private CarbonImmutable $periodStart;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['hl.alert_email' => 'alerts@example.test']);
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'secret-test', 'role' => 'admin', 'email_verified_at' => now()]);
        $this->periodStart = CarbonImmutable::parse($this->day, config('hl.timezone'))->startOfMonth();
        $this->period = $this->periodStart->format('Y-m');
        $this->actingAs($this->admin);
        app(BillingSettings::class)->set('start_period', CarbonImmutable::now()->subMonthNoOverflow()->format('Y-m'));
        auth()->logout();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    /** @return array<string, array{0: string, 1: ?float}> */
    public static function vatModes(): array
    {
        return ['aucune TVA' => ['none', null], 'HT + TVA 20 %' => ['ht_plus_vat', 20.0], 'TVA incluse 20 %' => ['ttc_included', 20.0]];
    }

    public function test_totals_split_a_tax_inclusive_amount_into_net_and_vat(): void
    {
        $this->actingAs($this->admin);
        $s = app(BillingSettings::class);
        // InvoiceDrafter lit les réglages via sa propre instance (cache par requête) : on le ré-instancie après chaque changement de régime.
        $totals = fn (float $amount) => $this->pick(app(InvoiceDrafter::class)->totals($amount));

        $s->set('vat_mode', 'none');
        $this->assertSame(['subtotal_ht' => 300.0, 'vat_amount' => 0.0, 'total_ttc' => 300.0], $totals(300));

        $s->set('vat_mode', 'ht_plus_vat');
        $s->set('vat_rate', 20);
        $this->assertSame(['subtotal_ht' => 300.0, 'vat_amount' => 60.0, 'total_ttc' => 360.0], $totals(300));

        $s->set('vat_mode', 'ttc_included');
        $this->assertSame(['subtotal_ht' => 250.0, 'vat_amount' => 50.0, 'total_ttc' => 300.0], $totals(300));
        $this->assertSame(['subtotal_ht' => 124.99, 'vat_amount' => 25.0, 'total_ttc' => 149.99], $totals(149.99));
    }

    #[DataProvider('vatModes')]
    public function test_invoice_credit_note_and_partner_credit_share_consistent_amounts(string $mode, ?float $rate): void
    {
        $booking = $this->completed();
        $this->unlock($mode, $rate);
        $this->travelTo($this->periodStart->addMonth()->day(6)->setTime(10, 0)->toMutable());
        Queue::fake([GenerateInvoicePdf::class, GenerateReceiptPdf::class, GenerateCreditNotePdf::class]);

        $commission = (float) LedgerEntry::where('booking_id', $booking->id)->sum('amount');
        $this->assertGreaterThan(0, $commission);
        $expected = $this->expected($commission, $mode, $rate);

        $invoice = app(InvoiceIssuer::class)->issue(app(InvoiceDrafter::class)->draft($this->partner(), $this->period), $this->admin);
        $this->assertSame($mode, $invoice->vat_mode);
        $this->assertAmounts($expected, $invoice->subtotal_ht, $invoice->vat_amount, $invoice->total_ttc, 'facture');
        $this->assertEqualsWithDelta($invoice->subtotal_ht + $invoice->vat_amount, $invoice->total_ttc, 0.011, 'facture : HT + TVA = TTC');
        $this->assertEqualsWithDelta($invoice->total_ttc, $invoice->balance, 0.001);

        app(PaymentRecorder::class)->record($invoice, $this->admin, ['amount' => (float) $invoice->total_ttc, 'paid_on' => CarbonImmutable::now(config('hl.timezone'))->toDateString(),
            'method' => 'bank_transfer', 'idempotency_key' => (string) Str::uuid()]);
        $this->assertSame('paid', $invoice->fresh()->status);

        $this->bookings->setPaymentStatus($booking->fresh(), 'admin', 'refunded');
        $this->artisan('hl:billing-mark-legacy', ['--apply' => true])->assertSuccessful();
        $entry = LedgerEntry::where('booking_id', $booking->id)->orderByDesc('id')->first();
        $this->assertSame('commission_reversal', $entry->type);
        $this->assertEqualsWithDelta(-$commission, (float) $entry->amount, 0.001);

        $service = app(CreditNoteService::class);
        $note = $service->issue($service->draft($invoice, $this->admin, [$entry->id], 'Prestation remboursée'), $this->admin);
        $this->assertSame($mode, $note->vat_mode);
        $this->assertAmounts($expected, $note->subtotal_ht, $note->vat_amount, $note->total_ttc, 'avoir');
        $this->assertEqualsWithDelta($note->subtotal_ht + $note->vat_amount, $note->total_ttc, 0.011, 'avoir : HT + TVA = TTC');
        $this->assertEqualsWithDelta($invoice->total_ttc, $note->total_ttc, 0.001, 'un avoir total rembourse exactement le TTC facturé');

        $invoice->refresh();
        $this->assertEqualsWithDelta($note->total_ttc, $invoice->credited_amount, 0.001);
        $this->assertSame(0.0, (float) $invoice->balance);
        $credit = PartnerCredit::where('credit_note_id', $note->id)->firstOrFail();
        $this->assertSame(PartnerCredit::STATUS_OPEN, $credit->status);
        $this->assertEqualsWithDelta($note->total_ttc, $credit->amount, 0.001, 'le crédit partenaire porte le TTC de l’avoir');

        $reversal = $service->reverse($note, $this->admin, 'Annulation de test');
        $this->assertAmounts(array_map(fn (float $v) => -$v, $expected), $reversal->subtotal_ht, $reversal->vat_amount, $reversal->total_ttc, 'avoir inverse');
    }

    /** @return array{subtotal_ht: float, vat_amount: float, total_ttc: float} */
    private function expected(float $commission, string $mode, ?float $rate): array
    {
        return match ($mode) {
            'ht_plus_vat' => ['subtotal_ht' => round($commission, 2), 'vat_amount' => round($commission * $rate / 100, 2), 'total_ttc' => round($commission * (1 + $rate / 100), 2)],
            'ttc_included' => ['subtotal_ht' => round($commission / (1 + $rate / 100), 2), 'vat_amount' => round($commission - round($commission / (1 + $rate / 100), 2), 2), 'total_ttc' => round($commission, 2)],
            default => ['subtotal_ht' => round($commission, 2), 'vat_amount' => 0.0, 'total_ttc' => round($commission, 2)],
        };
    }

    /** @param array{subtotal_ht: float, vat_amount: float, total_ttc: float} $expected */
    private function assertAmounts(array $expected, mixed $ht, mixed $vat, mixed $ttc, string $label): void
    {
        $this->assertEqualsWithDelta($expected['subtotal_ht'], (float) $ht, 0.001, "$label : HT");
        $this->assertEqualsWithDelta($expected['vat_amount'], (float) $vat, 0.001, "$label : TVA");
        $this->assertEqualsWithDelta($expected['total_ttc'], (float) $ttc, 0.001, "$label : TTC");
    }

    /** @return array{subtotal_ht: float, vat_amount: float, total_ttc: float} */
    private function pick(array $totals): array
    {
        return ['subtotal_ht' => (float) $totals['subtotal_ht'], 'vat_amount' => (float) $totals['vat_amount'], 'total_ttc' => (float) $totals['total_ttc']];
    }

    private function completed(): Booking
    {
        $result = $this->submit($this->intent('10:00', ['treatment' => $this->m->id, 'party' => 1]));
        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $booking = $result['booking'];
        $this->bookings->accept($booking, 'partner');
        $this->travelTo($booking->end_at->addHours(5)->toMutable());
        $this->bookings->complete($booking, 'admin');
        $this->travelBack();

        return $booking->fresh();
    }

    private function unlock(string $mode, ?float $rate): void
    {
        $this->artisan('hl:billing-mark-legacy', ['--apply' => true])->assertSuccessful();
        $this->app->bind(BillingGate::class, fn ($app, array $params) => new class($params['settings'] ?? $app->make(BillingSettings::class)) extends BillingGate
        {
            protected function issuanceLotsDelivered(): bool
            {
                return true;
            }
        });
        $s = app(BillingSettings::class);
        $this->actingAs($this->admin);
        $s->set('vat_mode', $mode);
        if ($rate !== null) {
            $s->set('vat_rate', $rate);
        }
        $s->set('accountant_validated_at', '2026-12-15');
        foreach (BillingSettings::ISSUER_REQUIRED as $f) {
            $s->set('issuer.'.$f, $f === 'ice' ? '001234567000089' : 'HL '.$f);
        }
        $this->spa->partner->update(['legal_form' => 'SARL', 'tax_id' => 'IF123', 'registry_number' => 'RC456', 'ice' => '001234567000089',
            'billing_address' => '1 rue Test', 'billing_city' => 'Marrakech', 'billing_email' => 'compta@example.test']);
        config(['hl.billing.acceptance_ref' => 'Recette staging', 'hl.billing.owner_authorization_ref' => 'Accord écrit']);
        $s->set('enabled', true);
        auth()->logout();
    }

    private function partner(): Partner
    {
        return $this->spa->partner->fresh();
    }
}
