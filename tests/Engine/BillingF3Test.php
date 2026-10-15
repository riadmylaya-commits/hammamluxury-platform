<?php

namespace Tests\Engine;

use App\Domain\Billing\BillingGate;
use App\Domain\Billing\BillingSettings;
use App\Domain\Billing\InvoiceDrafter;
use App\Domain\Billing\InvoiceIssuer;
use App\Domain\Billing\InvoiceReminders;
use App\Domain\Billing\PaymentException;
use App\Domain\Billing\PaymentRecorder;
use App\Domain\Billing\ReceiptPdf;
use App\Filament\Admin\Pages\OverpaymentsPage;
use App\Filament\Admin\Resources\InvoiceResource\Pages\ListInvoices;
use App\Filament\Admin\Resources\InvoiceResource\Pages\ViewInvoice;
use App\Filament\Admin\Widgets\UnpaidInvoices;
use App\Filament\Partner\Pages\Invoices;
use App\Jobs\GenerateInvoicePdf;
use App\Jobs\GenerateReceiptPdf;
use App\Models\ActivityLog;
use App\Models\BillingNotification;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\InvoiceOverpayment;
use App\Models\InvoicePayment;
use App\Models\InvoiceSequence;
use App\Models\LedgerEntry;
use App\Models\Partner;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use LogicException;

/** Lot F3 : paiements en ajout seul, soldes/statuts, contre-passations, trop-perçus, reçus d'espèces, plafond, rappels. Base de test uniquement. */
class BillingF3Test extends BookingFlowTestCase
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

    private function completed(string $time = '10:00'): Booking
    {
        $result = $this->submit($this->intent($time, ['treatment' => $this->m->id, 'party' => 1]));
        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $booking = $result['booking'];
        $this->bookings->accept($booking, 'partner');
        $this->travelTo($booking->end_at->addHours(5)->toMutable());
        $this->bookings->complete($booking, 'admin');
        $this->travelBack();

        return $booking->fresh();
    }

    private function unlock(): void
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
    }

    private function partner(): Partner
    {
        return $this->spa->partner->fresh();
    }

    /** Facture émise (PDF de facture mis en file, non exécuté) au 6 du mois suivant. */
    private function issued(): Invoice
    {
        $this->completed();
        $this->unlock();
        $this->travelTo($this->periodStart->addMonth()->day(6)->setTime(10, 0)->toMutable());
        Queue::fake([GenerateInvoicePdf::class, GenerateReceiptPdf::class]);
        $draft = app(InvoiceDrafter::class)->draft($this->partner(), $this->period);
        $this->assertNotNull($draft);

        return app(InvoiceIssuer::class)->issue($draft, $this->admin);
    }

    /** @param  array<string, mixed>  $extra */
    private function pay(Invoice $invoice, float $amount, string $method = 'bank_transfer', array $extra = []): InvoicePayment
    {
        return app(PaymentRecorder::class)->record($invoice, $this->admin, array_merge([
            'amount' => $amount, 'paid_on' => CarbonImmutable::now(config('hl.timezone'))->toDateString(), 'method' => $method,
            'reference' => 'VIR-'.uniqid(), 'idempotency_key' => (string) Str::uuid(),
        ], $extra));
    }

    private function ledgerHash(): string
    {
        return LedgerEntry::orderBy('id')->get()->map(fn ($e) => $e->id.':'.$e->amount.':'.$e->type.':'.$e->invoice_line_id)->implode('|');
    }

    private function expectPaymentError(string $reason, callable $fn): void
    {
        try {
            $fn();
            $this->fail("PaymentException $reason attendue.");
        } catch (PaymentException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    public function test_billing_stays_disabled_and_f3_does_not_lift_the_issuance_lock(): void
    {
        $this->assertFalse(app(BillingSettings::class)->enabled());
        $this->assertFalse(BillingGate::ISSUANCE_LOTS_DELIVERED);
        $this->assertContains('cash_limit', BillingSettings::KEYS);
        $this->assertSame(20000.0, app(BillingSettings::class)->get('cash_limit'));
        $this->actingAs($this->admin);
        $this->expectException(\InvalidArgumentException::class);
        app(BillingSettings::class)->set('cash_limit', 0);
    }

    public function test_payments_are_append_only(): void
    {
        $invoice = $this->issued();
        $payment = $this->pay($invoice, 10);
        try {
            $payment->update(['amount' => 99]);
            $this->fail('La modification du montant doit échouer.');
        } catch (LogicException) {
        }
        try {
            $payment->delete();
            $this->fail('La suppression doit échouer.');
        } catch (LogicException) {
        }
        $this->assertSame(10.0, $payment->fresh()->amount);
        $payment->fresh()->update(['receipt_status' => null]);
        $this->assertSame(1, InvoicePayment::count());
    }

    public function test_only_an_admin_can_record_a_payment_and_amount_must_be_positive(): void
    {
        $invoice = $this->issued();
        $partnerUser = $this->partner()->user;
        $this->expectPaymentError('forbidden', fn () => app(PaymentRecorder::class)->record($invoice, $partnerUser, ['amount' => 10, 'paid_on' => now()->toDateString(), 'method' => 'cash', 'idempotency_key' => 'k1']));
        $this->expectPaymentError('amount_not_positive', fn () => $this->pay($invoice, 0));
        $this->expectPaymentError('amount_not_positive', fn () => $this->pay($invoice, -5));
        $this->expectPaymentError('method_invalid', fn () => $this->pay($invoice, 5, 'cheque'));
        $this->expectPaymentError('paid_on_invalid', fn () => $this->pay($invoice, 5, 'bank_transfer', ['paid_on' => now()->addDay()->toDateString()]));
        $this->expectPaymentError('idempotency_key_invalid', fn () => $this->pay($invoice, 5, 'bank_transfer', ['idempotency_key' => '']));
        $this->assertSame(0, InvoicePayment::count());
        $this->pay($invoice, 0.01);
        $this->assertSame(1, InvoicePayment::count());
    }

    public function test_payments_cannot_be_recorded_on_a_draft(): void
    {
        $this->completed();
        $this->unlock();
        $this->travelTo($this->periodStart->addMonth()->day(1)->setTime(10, 0)->toMutable());
        $draft = app(InvoiceDrafter::class)->draft($this->partner(), $this->period);
        $this->expectPaymentError('invoice_not_issued', fn () => $this->pay($draft, 10));
    }

    public function test_balance_and_status_follow_one_two_and_several_payments(): void
    {
        $invoice = $this->issued();
        $total = (float) $invoice->total_ttc;
        $hash = $this->ledgerHash();
        $this->assertSame(Invoice::STATUS_ISSUED, $invoice->status);
        $this->assertSame($total, (float) $invoice->balance);

        $p1 = $this->pay($invoice, round($total / 2, 2));
        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_PARTIALLY_PAID, $invoice->status);
        $this->assertEqualsWithDelta($total - round($total / 2, 2), $invoice->balance, 0.001);
        $this->assertSame((float) $invoice->balance, $p1->balance_after);
        $this->assertNull($invoice->paid_at);
        $this->assertSame(1, BillingNotification::where('event', 'payment_partial')->where('recipient', 'compta@example.test')->count());

        $this->pay($invoice, 1);
        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_PARTIALLY_PAID, $invoice->status);

        $this->pay($invoice, (float) $invoice->balance);
        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertSame(0.0, (float) $invoice->balance);
        $this->assertEqualsWithDelta($total, $invoice->paid_amount, 0.001);
        $this->assertNotNull($invoice->paid_at);
        $this->assertSame(1, BillingNotification::where('event', 'payment_received')->count());
        $this->assertSame(2, BillingNotification::where('event', 'payment_partial')->count());
        $this->assertSame(0, InvoiceOverpayment::count());

        $this->expectPaymentError('invoice_closed', fn () => $this->pay($invoice, 1));
        $this->assertSame(3, InvoicePayment::count());
        $this->assertSame($hash, $this->ledgerHash());
        $this->assertSame(1, Invoice::count());
        $this->assertSame(3, ActivityLog::where('action', 'billing.payment_recorded')->count());
    }

    public function test_same_operation_key_is_idempotent_but_identical_legitimate_payments_are_both_accepted(): void
    {
        $invoice = $this->issued();
        $a = $this->pay($invoice, 10, 'bank_transfer', ['idempotency_key' => 'op-1']);
        $again = $this->pay($invoice, 10, 'bank_transfer', ['idempotency_key' => 'op-1']);
        $this->assertSame($a->id, $again->id);
        $this->assertSame(1, InvoicePayment::count());
        $b = $this->pay($invoice, 10, 'bank_transfer', ['idempotency_key' => 'op-2']);
        $this->assertNotSame($a->id, $b->id);
        $this->assertSame(2, InvoicePayment::count());
        $this->assertEqualsWithDelta(20.0, $invoice->fresh()->paid_amount, 0.001);
    }

    public function test_reversal_is_a_linked_negative_entry_recorded_once_and_restores_the_balance(): void
    {
        $invoice = $this->issued();
        $total = (float) $invoice->total_ttc;
        $payment = $this->pay($invoice, 30);
        $this->assertSame(Invoice::STATUS_PARTIALLY_PAID, $invoice->fresh()->status);

        $this->expectPaymentError('forbidden', fn () => app(PaymentRecorder::class)->reverse($payment, $this->partner()->user, 'x', 'r0'));
        $this->expectPaymentError('reason_required', fn () => app(PaymentRecorder::class)->reverse($payment, $this->admin, '  ', 'r0'));
        $reversal = app(PaymentRecorder::class)->reverse($payment, $this->admin, 'Erreur de saisie', 'rev-1');
        $this->assertSame(-30.0, $reversal->amount);
        $this->assertSame($payment->id, $reversal->reverses_payment_id);
        $this->assertSame('Erreur de saisie', $reversal->reversal_reason);
        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_ISSUED, $invoice->status);
        $this->assertSame(0.0, (float) $invoice->paid_amount);
        $this->assertEqualsWithDelta($total, $invoice->balance, 0.001);
        $this->assertSame(2, InvoicePayment::count());
        $this->assertSame(30.0, $payment->fresh()->amount, 'Le paiement d’origine reste intact.');

        $same = app(PaymentRecorder::class)->reverse($payment, $this->admin, 'Erreur de saisie', 'rev-1');
        $this->assertSame($reversal->id, $same->id);
        $this->expectPaymentError('already_reversed', fn () => app(PaymentRecorder::class)->reverse($payment, $this->admin, 'bis', 'rev-2'));
        $this->expectPaymentError('cannot_reverse_reversal', fn () => app(PaymentRecorder::class)->reverse($reversal, $this->admin, 'ter', 'rev-3'));
        $this->assertSame(2, InvoicePayment::count());
        $this->assertSame(1, ActivityLog::where('action', 'billing.payment_reversed')->count());

        $this->pay($invoice, $total);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $full = InvoicePayment::orderByDesc('id')->first();
        app(PaymentRecorder::class)->reverse($full, $this->admin, 'Virement rejeté', 'rev-4');
        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_ISSUED, $invoice->status);
        $this->assertNull($invoice->paid_at);
        $this->assertEqualsWithDelta($total, $invoice->balance, 0.001);
    }

    public function test_overpayment_marks_the_invoice_paid_flags_the_excess_and_never_allocates_it(): void
    {
        $invoice = $this->issued();
        $total = (float) $invoice->total_ttc;
        $other = Invoice::create(['partner_id' => $invoice->partner_id, 'period' => '2020-01', 'status' => 'issued', 'currency' => 'MAD', 'number' => 'HL-2020-00001',
            'issued_at' => now(), 'due_at' => now()->addDays(30), 'subtotal_ht' => 100, 'vat_mode' => 'none', 'vat_rate' => 0, 'vat_amount' => 0, 'total_ttc' => 100, 'balance' => 100,
            'partner_snapshot' => [], 'issuer_snapshot' => [], 'lines_count' => 0]);
        $payment = $this->pay($invoice, $total + 25.5);
        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertSame(0.0, (float) $invoice->balance);
        $this->assertEqualsWithDelta($total + 25.5, $invoice->paid_amount, 0.001, 'Le paiement est enregistré intégralement.');
        $over = InvoiceOverpayment::sole();
        $this->assertSame($payment->id, $over->payment_id);
        $this->assertSame(25.5, $over->amount);
        $this->assertSame(InvoiceOverpayment::STATUS_OPEN, $over->status);
        $this->assertSame(1, BillingNotification::where('event', 'overpayment_detected')->where('recipient', 'alerts@example.test')->count());
        $this->assertSame(1, BillingNotification::where('event', 'payment_received')->count());
        $this->assertSame(100.0, (float) $other->fresh()->balance, 'Aucune imputation automatique sur une autre facture.');
        $this->assertSame(0, InvoicePayment::where('invoice_id', $other->id)->count());
        try {
            $over->delete();
            $this->fail('Un trop-perçu ne se supprime pas.');
        } catch (LogicException) {
        }

        app(PaymentRecorder::class)->reverse($payment, $this->admin, 'Montant erroné', 'rev-over');
        $this->assertSame('reversed', $over->fresh()->status);
        $this->assertSame($this->admin->id, $over->fresh()->decided_by);
        $this->assertSame(Invoice::STATUS_ISSUED, $invoice->fresh()->status);
    }

    public function test_cash_payments_get_sequential_yearly_receipt_numbers_and_the_legal_limit_blocks_strictly(): void
    {
        $invoice = $this->issued();
        $this->actingAs($this->admin);
        app(BillingSettings::class)->set('cash_limit', 50);
        auth()->logout();
        $year = CarbonImmutable::now(config('hl.timezone'))->year;
        $c1 = $this->pay($invoice, 20, 'cash');
        $this->assertSame("HL-R-$year-00001", $c1->receipt_number);
        $this->assertSame(InvoicePayment::RECEIPT_PENDING, $c1->receipt_status);
        Queue::assertPushed(GenerateReceiptPdf::class, fn (GenerateReceiptPdf $job) => $job->paymentId === $c1->id);
        $this->assertSame(0, BillingNotification::where('event', 'payment_partial')->count(), 'Espèces : notification seulement avec le reçu.');
        $c2 = $this->pay($invoice, 30, 'cash');
        $this->assertSame("HL-R-$year-00002", $c2->receipt_number);
        $this->assertSame(50.0, app(PaymentRecorder::class)->cashReceived($invoice));

        $this->expectPaymentError('cash_limit_exceeded', fn () => $this->pay($invoice, 0.01, 'cash'));
        $this->assertSame(2, InvoicePayment::count(), 'Blocage strict : rien n’est enregistré, aucun numéro consommé.');
        $this->assertSame(2, (int) InvoiceSequence::where('kind', 'receipt')->where('year', $year)->value('last_number'));
        $t = $this->pay($invoice, 10, 'bank_transfer');
        $this->assertNull($t->receipt_number, 'Le virement n’est pas plafonné et n’a pas de reçu.');

        app(PaymentRecorder::class)->reverse($c2, $this->admin, 'Erreur', 'rev-c2');
        $this->assertSame(20.0, app(PaymentRecorder::class)->cashReceived($invoice), 'La contre-passation libère le plafond.');
        $c3 = $this->pay($invoice, 30, 'cash');
        $this->assertSame("HL-R-$year-00003", $c3->receipt_number, 'Un numéro n’est jamais réutilisé.');
        $this->assertNull(InvoicePayment::whereKey($c3->id + 0)->first()->reverses_payment_id);
        $reversal = InvoicePayment::where('reverses_payment_id', $c2->id)->sole();
        $this->assertNull($reversal->receipt_number);
    }

    public function test_receipt_pdf_is_written_and_hashed_before_the_partner_is_notified_and_failures_keep_the_number(): void
    {
        $invoice = $this->issued();
        $payment = $this->pay($invoice, 15, 'cash');
        $this->assertSame(0, BillingNotification::where('audience', 'partner')->whereIn('event', ['payment_partial', 'payment_received'])->count());

        app()->call([new GenerateReceiptPdf($payment->id), 'handle']);
        $payment->refresh();
        $this->assertSame(InvoicePayment::RECEIPT_READY, $payment->receipt_status);
        $this->assertSame('receipts/'.$payment->paid_on->format('Y').'/'.$payment->receipt_number.'.pdf', $payment->receipt_path);
        $bytes = (string) Storage::disk('local')->get($payment->receipt_path);
        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertSame(hash('sha256', $bytes), $payment->receipt_sha256);
        $this->assertTrue(app(ReceiptPdf::class)->verify($payment));
        $this->assertSame(1, BillingNotification::where('event', 'payment_partial')->where('recipient', 'compta@example.test')->count());
        $this->assertSame($payment->receipt_number, BillingNotification::where('event', 'payment_partial')->sole()->payload['receipt']);

        app()->call([new GenerateReceiptPdf($payment->id), 'handle']);
        $this->assertSame(1, BillingNotification::where('event', 'payment_partial')->count(), 'Rejouer le job ne renvoie rien.');
        $this->assertSame(1, $payment->fresh()->receipt_attempts);

        // Échec : le fichier disparaît et un répertoire occupe son chemin.
        $path = $payment->receipt_path;
        Storage::disk('local')->delete($path);
        Storage::disk('local')->makeDirectory($path);
        $payment->update(['receipt_status' => InvoicePayment::RECEIPT_FAILED]);
        app()->call([new GenerateReceiptPdf($payment->id), 'handle']);
        $payment->refresh();
        $this->assertSame(InvoicePayment::RECEIPT_FAILED, $payment->receipt_status);
        $this->assertNull($payment->receipt_path);
        $this->assertNull($payment->receipt_sha256);
        $this->assertFalse(app(ReceiptPdf::class)->verify($payment));
        $this->assertSame(1, BillingNotification::where('event', 'receipt_pdf_failed')->where('recipient', 'alerts@example.test')->count());
        $this->assertSame(1, InvoicePayment::count());
        $this->assertSame(1, (int) InvoiceSequence::where('kind', 'receipt')->value('last_number'), 'Aucun second reçu.');

        Storage::disk('local')->deleteDirectory($path);
        $this->artisan('hl:billing-receipt-retry')->assertSuccessful();
        Queue::assertPushed(GenerateReceiptPdf::class, 2);
        app()->call([new GenerateReceiptPdf($payment->id), 'handle']);
        $payment->refresh();
        $this->assertSame(InvoicePayment::RECEIPT_READY, $payment->receipt_status);
        $this->assertSame($path, $payment->receipt_path);
        $this->assertSame(hash('sha256', (string) Storage::disk('local')->get($path)), $payment->receipt_sha256);
        $this->assertSame(1, BillingNotification::where('event', 'payment_partial')->count(), 'Toujours une seule notification partenaire.');
    }

    public function test_reminders_run_once_per_step_mark_overdue_and_stop_after_full_payment(): void
    {
        $invoice = $this->issued();
        $due = $invoice->due_at;
        $run = fn (CarbonImmutable $day) => app(InvoiceReminders::class)->run($day->setTime(8, 0));
        $count = fn (string $event) => BillingNotification::where('event', $event)->where('notifiable_id', $invoice->id)->count();

        $run($due->subDays(6));
        $this->assertSame(0, $count('reminder_before_due'));
        $run($due->subDays(5));
        $run($due->subDays(5));
        $run($due->subDays(4));
        $this->assertSame(1, $count('reminder_before_due'));
        $this->assertSame(Invoice::STATUS_ISSUED, $invoice->fresh()->status);

        $run($due);
        $run($due);
        $this->assertSame(1, $count('reminder_due'));
        $this->assertSame(Invoice::STATUS_ISSUED, $invoice->fresh()->status, 'Le jour de l’échéance n’est pas encore un retard.');

        $run($due->addDay());
        $this->assertSame(Invoice::STATUS_OVERDUE, $invoice->fresh()->status);
        $this->assertSame(0, $count('reminder_late_1'));
        $run($due->addDays(7));
        $run($due->addDays(8));
        $this->assertSame(1, $count('reminder_late_1'));
        $this->assertSame(0, $count('invoice_late'));

        $this->travelTo($due->addDays(8)->setTime(11, 0)->toMutable());
        $this->pay($invoice, 5);
        $this->assertSame(Invoice::STATUS_OVERDUE, $invoice->fresh()->status, 'Un paiement partiel après échéance reste en retard.');
        $run($due->addDays(15));
        $run($due->addDays(16));
        $this->assertSame(1, $count('reminder_late_2'));
        $this->assertSame(1, BillingNotification::where('event', 'invoice_late')->where('recipient', 'alerts@example.test')->count());
        $this->assertCount(4, $invoice->fresh()->reminders_sent);

        $this->pay($invoice, (float) $invoice->fresh()->balance);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $before = BillingNotification::count();
        $run($due->addDays(30));
        $this->assertSame($before, BillingNotification::count(), 'Plus aucun rappel après règlement intégral.');
        $this->artisan('hl:billing-reminders')->assertSuccessful();
        $this->assertSame($before, BillingNotification::count());
        $this->assertSame(4, BillingNotification::whereIn('event', array_keys(InvoiceReminders::STEPS))->count());
    }

    public function test_reminders_catch_up_a_late_invoice_without_duplicating_and_are_scheduled_at_8am_casablanca(): void
    {
        $invoice = $this->issued();
        $this->assertSame(Invoice::STATUS_ISSUED, $invoice->status);
        app(InvoiceReminders::class)->run($invoice->due_at->addDays(20)->setTime(8, 0));
        $this->assertSame(Invoice::STATUS_OVERDUE, $invoice->fresh()->status);
        $steps = fn () => BillingNotification::where('notifiable_id', $invoice->id)->whereIn('event', array_keys(InvoiceReminders::STEPS))->pluck('event')->all();
        $this->assertSame(['reminder_late_2'], $steps(), 'Rattrapage : seule la dernière étape échue est envoyée.');
        $this->assertSame(1, BillingNotification::where('event', 'invoice_late')->count());
        $sent = $invoice->fresh()->reminders_sent;
        $this->assertSame('skipped', $sent['reminder_before_due']);
        $this->assertSame('skipped', $sent['reminder_due']);
        $this->assertSame('skipped', $sent['reminder_late_1']);
        $this->assertNotSame('skipped', $sent['reminder_late_2']);
        app(InvoiceReminders::class)->run($invoice->due_at->addDays(21)->setTime(8, 0));
        $this->assertSame(['reminder_late_2'], $steps());

        // Facture émise à J−2 : l'étape J−5 (la plus récente échue) part, puis J le jour de l'échéance ; rien n'est doublé.
        $second = Invoice::create(['partner_id' => $invoice->partner_id, 'period' => '2020-02', 'status' => 'issued', 'currency' => 'MAD', 'number' => 'HL-2020-00002',
            'issued_at' => now(), 'due_at' => $invoice->due_at->addDays(23)->toDateString(), 'subtotal_ht' => 50, 'vat_mode' => 'none', 'vat_rate' => 0, 'vat_amount' => 0, 'total_ttc' => 50,
            'balance' => 50, 'partner_snapshot' => ['billing_email' => 'compta@example.test'], 'issuer_snapshot' => [], 'lines_count' => 0]);
        app(InvoiceReminders::class)->run($invoice->due_at->addDays(21)->setTime(8, 0));
        $this->assertSame(['reminder_before_due'], BillingNotification::where('notifiable_id', $second->id)->pluck('event')->all());
        app(InvoiceReminders::class)->run($second->due_at->setTime(8, 0));
        app(InvoiceReminders::class)->run($second->due_at->setTime(8, 0));
        $this->assertSame(['reminder_before_due', 'reminder_due'], BillingNotification::where('notifiable_id', $second->id)->orderBy('id')->pluck('event')->all());

        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command, 'hl:billing-reminders'));
        $this->assertCount(1, $events);
        $this->assertSame('0 8 * * *', $events->first()->expression);
        $this->assertSame('Africa/Casablanca', $events->first()->timezone);
    }

    public function test_admin_records_a_payment_from_the_invoice_page_and_sees_the_history(): void
    {
        $invoice = $this->issued();
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ViewInvoice::class, ['record' => $invoice->id])->assertOk()->assertSee(__('billing.no_payments'))
            ->callAction('recordPayment', ['amount' => 12.5, 'paid_on' => now()->toDateString(), 'method' => 'bank_transfer', 'reference' => 'VIR 42', 'idempotency_key' => 'ui-1'])
            ->assertNotified(__('billing.payment_recorded'));
        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_PARTIALLY_PAID, $invoice->status);
        $this->assertSame(12.5, (float) $invoice->paid_amount);
        $this->assertSame('ui-1', InvoicePayment::sole()->idempotency_key);
        Livewire::test(ViewInvoice::class, ['record' => $invoice->id])->assertOk()->assertSee('VIR 42')->assertSee(__('billing.reverse_payment'))
            ->callAction('recordPayment', ['amount' => -1, 'paid_on' => now()->toDateString(), 'method' => 'bank_transfer', 'idempotency_key' => 'ui-2'])
            ->assertHasActionErrors(['amount']);
        $this->assertSame(1, InvoicePayment::count());
        Livewire::test(ViewInvoice::class, ['record' => $invoice->id])
            ->callAction('reversePayment', ['reason' => 'Doublon', 'idempotency_key' => 'ui-3'], ['payment' => InvoicePayment::sole()->id])
            ->assertNotified(__('billing.payment_reversed'));
        $this->assertSame(2, InvoicePayment::count());
        $this->assertSame(Invoice::STATUS_ISSUED, $invoice->fresh()->status);
        Livewire::test(ListInvoices::class)->set('activeTab', 'issued')->assertOk()->assertCanSeeTableRecords([$invoice]);
        Livewire::test(UnpaidInvoices::class)->assertOk()->assertSee(__('billing.unpaid_widget'));
        Livewire::test(OverpaymentsPage::class)->assertOk();
    }

    public function test_partner_sees_balance_and_history_and_only_downloads_their_own_receipt(): void
    {
        $invoice = $this->issued();
        $payment = $this->pay($invoice, 15, 'cash');
        app()->call([new GenerateReceiptPdf($payment->id), 'handle']);
        $this->assertSame(InvoicePayment::RECEIPT_READY, $payment->fresh()->receipt_status);
        $invoice->refresh();

        $this->actingAs($this->partner()->user);
        Filament::setCurrentPanel(Filament::getPanel('partner'));
        Filament::setTenant($this->spa);
        Livewire::test(Invoices::class)->assertSee($invoice->number)->assertSee(number_format($invoice->balance, 2, ',', ' '))
            ->call('select', $invoice->id)->assertSee(__('billing.payment_history'))->assertSee($payment->receipt_number)->assertDontSee(__('billing.reverse_payment'))
            ->callAction('receipt', arguments: ['payment' => $payment->id])->assertFileDownloaded($payment->receipt_number.'.pdf');

        // Autre entité juridique : le reçu n'est ni résolu ni servi.
        $other = Partner::create(['company_name' => 'Autre SARL', 'status' => 'pending', 'user_id' => User::create(['name' => 'P2', 'email' => 'p2@example.test', 'password' => 'secret-test', 'role' => 'partner', 'email_verified_at' => now()])->id]);
        $otherSpa = $this->spa->replicate();
        $otherSpa->forceFill(['partner_id' => $other->id, 'name' => 'Autre spa', 'slug' => 'autre-spa'])->save();
        $this->actingAs($other->user);
        Filament::setTenant($otherSpa->fresh());
        Livewire::test(Invoices::class)->assertDontSee($invoice->number)
            ->callAction('receipt', arguments: ['payment' => $payment->id])->assertNotified(__('billing.pdf_corrupt'));

        auth()->logout();
        $this->assertContains($this->get('/storage/'.$payment->receipt_path)->getStatusCode(), [403, 404]);
    }
}
