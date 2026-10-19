<?php

namespace Tests\Engine;

use App\Domain\Billing\BillingGate;
use App\Domain\Billing\BillingNotifier;
use App\Domain\Billing\BillingSettings;
use App\Domain\Billing\CreditNoteException;
use App\Domain\Billing\CreditNotePdf;
use App\Domain\Billing\CreditNoteService;
use App\Domain\Billing\InvoiceDrafter;
use App\Domain\Billing\InvoiceIssuer;
use App\Domain\Billing\PartnerCreditService;
use App\Domain\Billing\PaymentException;
use App\Domain\Billing\PaymentRecorder;
use App\Domain\Billing\StatementBuilder;
use App\Filament\Admin\Pages\OverpaymentsPage;
use App\Filament\Admin\Pages\PartnerCreditsPage;
use App\Filament\Admin\Pages\PostIssueCorrectionsPage;
use App\Filament\Admin\Resources\InvoiceResource\Pages\ViewInvoice;
use App\Filament\Partner\Pages\Invoices;
use App\Jobs\GenerateCreditNotePdf;
use App\Jobs\GenerateInvoicePdf;
use App\Jobs\GenerateReceiptPdf;
use App\Models\ActivityLog;
use App\Models\BillingNotification;
use App\Models\Booking;
use App\Models\CreditNote;
use App\Models\CreditNoteLine;
use App\Models\Invoice;
use App\Models\InvoiceOverpayment;
use App\Models\InvoicePayment;
use App\Models\LedgerEntry;
use App\Models\Partner;
use App\Models\PartnerCredit;
use App\Models\PartnerCreditApplication;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use LogicException;

/** Lot F4 : avoirs, corrections post-émission, crédits partenaires et résolution des trop-perçus. Base de test uniquement. */
class BillingF4Test extends BookingFlowTestCase
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

    /** Facture émise au 6 du mois suivant pour les réservations données (PDF mis en file, non exécuté). @return array{0: Invoice, 1: list<Booking>} */
    private function issued(int $bookings = 1): array
    {
        $list = [];
        foreach (range(1, $bookings) as $i) {
            $list[] = $this->completed($i === 1 ? '10:00' : '1'.($i).':00');
        }
        $this->unlock();
        $this->travelTo($this->periodStart->addMonth()->day(6)->setTime(10, 0)->toMutable());
        Queue::fake([GenerateInvoicePdf::class, GenerateReceiptPdf::class, GenerateCreditNotePdf::class]);
        $draft = app(InvoiceDrafter::class)->draft($this->partner(), $this->period);
        $this->assertNotNull($draft);

        return [app(InvoiceIssuer::class)->issue($draft, $this->admin), $list];
    }

    /** Correction négative post-émission : la réservation facturée est remboursée au client → contre-écriture de commission. */
    private function correction(Booking $booking): LedgerEntry
    {
        $this->bookings->setPaymentStatus($booking->fresh(), 'admin', 'refunded');
        $this->artisan('hl:billing-mark-legacy', ['--apply' => true])->assertSuccessful();
        $entry = LedgerEntry::where('booking_id', $booking->id)->orderByDesc('id')->first();
        $this->assertSame('commission_reversal', $entry->type);
        $this->assertSame(LedgerEntry::SCOPE_BILLABLE, $entry->billing_scope);
        $this->assertLessThan(0, $entry->amount);

        return $entry;
    }

    private function pay(Invoice $invoice, float $amount, string $method = 'bank_transfer'): InvoicePayment
    {
        return app(PaymentRecorder::class)->record($invoice, $this->admin, ['amount' => $amount, 'paid_on' => CarbonImmutable::now(config('hl.timezone'))->toDateString(),
            'method' => $method, 'idempotency_key' => (string) Str::uuid()]);
    }

    private function draft(Invoice $invoice, array $entries, string $reason = 'Prestation remboursée'): CreditNote
    {
        return app(CreditNoteService::class)->draft($invoice, $this->admin, array_map(fn (LedgerEntry $e) => $e->id, $entries), $reason);
    }

    private function ledgerHash(): string
    {
        return LedgerEntry::orderBy('id')->get()->map(fn ($e) => $e->id.':'.$e->amount.':'.$e->type.':'.$e->billing_scope)->implode('|');
    }

    private function expectError(string $reason, callable $fn, string $message = ''): void
    {
        try {
            $fn();
            $this->fail("Expected CreditNoteException [$reason]");
        } catch (CreditNoteException $e) {
            $this->assertSame($reason, $e->reason, $message);
        }
    }

    public function test_f4_keeps_billing_disabled_by_default_and_all_locks_active(): void
    {
        $this->assertFalse(app(BillingSettings::class)->enabled());
        $this->assertFalse(config('hl.billing.enabled'));
        $gate = app(BillingGate::class);
        $this->assertContains('billing_disabled', $gate->globalBlockers());
        $this->assertFalse($gate->canIssue());
        $this->assertSame(10, app(BillingSettings::class)->get('correction_due_days'));
        [$invoice] = $this->issued();
        $this->actingAs($this->admin);
        app(BillingSettings::class)->set('enabled', false);
        auth()->logout();
        $entry = $this->correction($invoice->lines->first()->booking);
        $note = $this->draft($invoice, [$entry]);
        $this->assertContains('billing_disabled', app(CreditNoteService::class)->blockers($note));
        $this->expectError('blocked', fn () => app(CreditNoteService::class)->issue($note, $this->admin));
    }

    public function test_negative_correction_after_invoicing_is_excluded_from_later_statements_and_queued_for_a_credit_note(): void
    {
        [$invoice, [$booking]] = $this->issued();
        $hash = $this->ledgerHash();
        $this->assertCount(0, app(CreditNoteService::class)->pendingCorrections());
        $entry = $this->correction($booking);
        $this->assertSame($hash.'|'.$entry->id.':'.$entry->amount.':commission_reversal:billable', $this->ledgerHash());

        $pending = app(CreditNoteService::class)->pendingCorrections();
        $this->assertCount(1, $pending);
        $this->assertSame($entry->id, $pending[0]['entry']->id);
        $this->assertSame($invoice->id, $pending[0]['invoice']->id);
        $this->assertSame(0, $pending[0]['age_days']);

        $next = app(StatementBuilder::class)->build($this->partner(), $this->periodStart->addMonth());
        $this->assertSame(0.0, $next->total);
        $this->assertCount(1, $next->excluded);
        $this->assertSame('credit_note', $next->excluded[0]['exclusion']);
        $this->assertNull(app(InvoiceDrafter::class)->draft($this->partner(), $this->periodStart->addMonth()->format('Y-m')), 'aucune facture négative suivante');

        $this->travelTo(CarbonImmutable::now()->addDays(12)->toMutable());
        $this->assertSame(12, app(CreditNoteService::class)->pendingCorrections()[0]['age_days']);
        $this->artisan('hl:billing-correction-reminders')->assertSuccessful();
        $this->artisan('hl:billing-correction-reminders')->assertSuccessful();
        $this->assertSame(1, BillingNotification::where('event', 'corrections_overdue')->count(), 'un seul rappel par écriture');
        $this->assertSame($hash.'|'.$entry->id.':'.$entry->amount.':commission_reversal:billable', $this->ledgerHash());
    }

    public function test_credit_note_on_an_unpaid_invoice_is_issued_once_with_a_yearly_number_and_cancels_it_when_total(): void
    {
        [$invoice, [$a, $b]] = $this->issued(2);
        $total = (float) $invoice->total_ttc;
        $entryA = $this->correction($a);
        $service = app(CreditNoteService::class);

        $note = $this->draft($invoice, [$entryA]);
        $this->assertTrue($note->isDraft());
        $this->assertNull($note->number);
        $this->assertEqualsWithDelta(-$entryA->amount, $note->total_ttc, 0.001);
        $this->assertSame(1, $note->lines_count);
        $this->assertSame($entryA->id, $note->lines->first()->ledger_entry_id);
        $this->expectError('entry_not_pending', fn () => $this->draft($invoice, [$entryA]), 'une écriture déjà en brouillon n’est plus éligible');
        $this->assertCount(0, $service->pendingCorrections(), 'une écriture en brouillon n’est plus en attente');

        $issued = $service->issue($note, $this->admin);
        $this->assertSame('HL-A-'.CarbonImmutable::now()->year.'-00001', $issued->number);
        $this->assertTrue($issued->isIssued());
        $this->assertSame(CreditNote::PDF_PENDING, $issued->pdf_status);
        Queue::assertPushed(GenerateCreditNotePdf::class, fn ($job) => $job->creditNoteId === $issued->id);
        $invoice->refresh();
        $this->assertEqualsWithDelta($issued->total_ttc, $invoice->credited_amount, 0.001);
        $this->assertEqualsWithDelta($total - $issued->total_ttc, $invoice->balance, 0.001);
        $this->assertSame(Invoice::STATUS_ISSUED, $invoice->status);
        $this->assertEqualsWithDelta($invoice->balance, $issued->invoice_balance_after, 0.001);
        $this->assertSame(0, PartnerCredit::count());
        $this->expectError('already_issued', fn () => $service->issue($issued, $this->admin));

        // Avoir émis : immuable, non supprimable.
        try {
            $issued->update(['total_ttc' => 1]);
            $this->fail('issued credit note must be immutable');
        } catch (LogicException) {
        }
        try {
            $issued->delete();
            $this->fail('issued credit note must not be deletable');
        } catch (LogicException) {
        }
        try {
            $issued->lines->first()->update(['amount' => 1]);
            $this->fail('issued credit note lines must be immutable');
        } catch (LogicException) {
        }

        // Second avoir soldant la facture sans aucun paiement → annulation totale (`cancelled`), facture et PDF conservés.
        $entryB = $this->correction($b);
        $second = $service->issue($this->draft($invoice, [$entryB]), $this->admin);
        $this->assertSame('HL-A-'.CarbonImmutable::now()->year.'-00002', $second->number);
        $invoice->refresh();
        $this->assertEqualsWithDelta($total, $invoice->credited_amount, 0.001);
        $this->assertSame(0.0, (float) $invoice->balance);
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->status);
        $this->assertNotNull($invoice->cancelled_at);
        $this->assertNull($invoice->paid_at);
        $this->assertNotNull($invoice->number);
        $this->assertSame(2, $invoice->lines()->count());
        try {
            $this->pay($invoice, 10);
            $this->fail('a cancelled invoice must refuse payments');
        } catch (PaymentException $e) {
            $this->assertSame('invoice_closed', $e->reason);
        }
        $this->assertSame(2, ActivityLog::where('action', 'billing.credit_note_issued')->count());
    }

    public function test_credit_note_cannot_exceed_the_invoice_and_only_an_admin_can_act(): void
    {
        [$invoice, [$a]] = $this->issued();
        $entry = $this->correction($a);
        $partnerUser = $this->spa->partner->user;
        $service = app(CreditNoteService::class);
        $this->expectError('forbidden', fn () => $service->draft($invoice, $partnerUser, [$entry->id], 'x'));
        $this->expectError('reason_required', fn () => $service->draft($invoice, $this->admin, [$entry->id], '  '));
        $this->expectError('no_entries', fn () => $service->draft($invoice, $this->admin, [], 'x'));
        $this->expectError('entry_not_pending', fn () => $service->draft($invoice, $this->admin, [$invoice->lines->first()->ledger_entry_id], 'x'));
        $note = $this->draft($invoice, [$entry]);
        $this->expectError('forbidden', fn () => $service->issue($note, $partnerUser));
        $this->expectError('forbidden', fn () => $service->discard($note, $partnerUser));

        // Un avoir trop élevé (écriture corrigée manuellement au-delà de la facture) est refusé.
        LedgerEntry::whereKey($entry->id)->update(['amount' => -((float) $invoice->total_ttc + 1)]);
        $service->discard($note, $this->admin);
        $this->assertSame(0, CreditNote::count());
        $this->expectError('exceeds_invoice', fn () => $this->draft($invoice, [$entry->fresh()]));
    }

    public function test_credit_note_on_a_paid_invoice_creates_an_open_partner_credit_and_never_refunds_automatically(): void
    {
        [$invoice, [$a]] = $this->issued();
        $total = (float) $invoice->total_ttc;
        $this->pay($invoice, $total);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $entry = $this->correction($a);
        $issued = app(CreditNoteService::class)->issue($this->draft($invoice, [$entry]), $this->admin);

        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status, 'une facture payée ne devient jamais cancelled');
        $this->assertSame(0.0, (float) $invoice->balance);
        $credit = PartnerCredit::sole();
        $this->assertSame(PartnerCredit::SOURCE_CREDIT_NOTE, $credit->source);
        $this->assertSame($issued->id, $credit->credit_note_id);
        $this->assertEqualsWithDelta($issued->total_ttc, $credit->amount, 0.001);
        $this->assertSame(PartnerCredit::STATUS_OPEN, $credit->status);
        $this->assertSame(0, PartnerCreditApplication::count());
        $this->assertSame(0, InvoiceOverpayment::count(), 'un avoir n’est pas un trop-perçu de paiement');
    }

    public function test_reversal_credit_note_is_linked_negative_recorded_once_and_refused_when_the_credit_was_used(): void
    {
        [$invoice, [$a, $b]] = $this->issued(2);
        $service = app(CreditNoteService::class);
        $entryA = $this->correction($a);
        $original = $service->issue($this->draft($invoice, [$entryA]), $this->admin);
        $balanceBefore = (float) $invoice->fresh()->balance;

        $this->expectError('reason_required', fn () => $service->reverse($original, $this->admin, ' '));
        $reversal = $service->reverse($original, $this->admin, 'Avoir émis par erreur');
        $this->assertSame($original->id, $reversal->reverses_credit_note_id);
        $this->assertEqualsWithDelta(-$original->total_ttc, $reversal->total_ttc, 0.001);
        $this->assertSame(0, $reversal->lines_count);
        $this->assertTrue($reversal->isIssued());
        $this->assertSame('HL-A-'.CarbonImmutable::now()->year.'-00002', $reversal->number);
        $invoice->refresh();
        $this->assertSame(0.0, (float) $invoice->credited_amount);
        $this->assertEqualsWithDelta($balanceBefore + $original->total_ttc, $invoice->balance, 0.001);
        $this->assertEqualsWithDelta((float) $invoice->total_ttc, $invoice->balance, 0.001);
        $this->expectError('already_reversed', fn () => $service->reverse($original, $this->admin, 'bis'));
        $this->expectError('cannot_reverse', fn () => $service->reverse($reversal, $this->admin, 'ter'));
        $this->assertSame(1, CreditNoteLine::count(), 'l’écriture reste consommée : une correction = un seul avoir');
        $this->assertCount(0, $service->pendingCorrections());

        // Crédit issu d'un avoir sur facture payée : annulation possible tant qu'il n'est pas consommé, refusée ensuite.
        $this->pay($invoice, (float) $invoice->total_ttc);
        $entryB = $this->correction($b);
        $paidNote = $service->issue($this->draft($invoice, [$entryB]), $this->admin);
        $credit = PartnerCredit::where('credit_note_id', $paidNote->id)->sole();
        app(PartnerCreditService::class)->refund($credit, $this->admin, ['amount' => 1, 'refund_on' => CarbonImmutable::now()->toDateString(), 'method' => 'bank_transfer', 'reference' => 'VIR-1'], 'rf-1');
        $this->expectError('credit_already_used', fn () => $service->reverse($paidNote, $this->admin, 'trop tard'));
        $this->assertSame(PartnerCredit::STATUS_OPEN, $credit->fresh()->status);
    }

    public function test_credit_note_pdf_is_written_and_hashed_before_the_partner_is_notified_and_failures_keep_the_number(): void
    {
        [$invoice, [$a]] = $this->issued();
        $issued = app(CreditNoteService::class)->issue($this->draft($invoice, [$this->correction($a)]), $this->admin);
        Queue::fake();

        $this->app->bind(CreditNotePdf::class, fn () => new class extends CreditNotePdf
        {
            public function render(CreditNote $note): string
            {
                return 'not a pdf';
            }
        });
        (new GenerateCreditNotePdf($issued->id))->handle(app(CreditNotePdf::class), app(BillingNotifier::class));
        $issued->refresh();
        $this->assertSame(CreditNote::PDF_FAILED, $issued->pdf_status);
        $this->assertNull($issued->pdf_path);
        $this->assertNull($issued->pdf_sha256);
        $this->assertSame(1, $issued->pdf_attempts);
        $this->assertSame('HL-A-'.CarbonImmutable::now()->year.'-00001', $issued->number);
        $this->assertSame(0, BillingNotification::where('event', 'credit_note_issued')->count());
        $this->assertSame(1, BillingNotification::where('event', 'credit_note_pdf_failed')->where('audience', 'admin')->count());

        $this->app->bind(CreditNotePdf::class, fn () => new CreditNotePdf);
        $this->artisan('hl:billing-credit-note-retry')->assertSuccessful();
        Queue::assertPushed(GenerateCreditNotePdf::class);
        (new GenerateCreditNotePdf($issued->id))->handle(app(CreditNotePdf::class), app(BillingNotifier::class));
        $issued->refresh();
        $this->assertSame(CreditNote::PDF_READY, $issued->pdf_status);
        $this->assertTrue(Storage::disk('local')->exists($issued->pdf_path));
        $this->assertStringStartsWith('%PDF', (string) Storage::disk('local')->get($issued->pdf_path));
        $this->assertTrue(app(CreditNotePdf::class)->verify($issued));
        $this->assertSame(1, BillingNotification::where('event', 'credit_note_issued')->where('recipient', 'compta@example.test')->count());
        (new GenerateCreditNotePdf($issued->id))->handle(app(CreditNotePdf::class), app(BillingNotifier::class));
        $this->assertSame(1, BillingNotification::where('event', 'credit_note_issued')->count(), 'rejouer ne renvoie pas');
        $this->assertSame(1, CreditNote::count(), 'jamais de second avoir');
    }

    public function test_overpayment_becomes_a_credit_applied_once_to_another_invoice_without_creating_a_new_overpayment(): void
    {
        [$invoice] = $this->issued();
        $total = (float) $invoice->total_ttc;
        $this->pay($invoice, $total + 40);
        $over = InvoiceOverpayment::sole();
        $this->assertEqualsWithDelta(40, $over->amount, 0.001);
        $credits = app(PartnerCreditService::class);
        $credit = $credits->fromOverpayment($over, $this->admin);
        $this->assertSame($credit->id, $credits->fromOverpayment($over->fresh(), $this->admin)->id, 'idempotent');
        $this->assertSame(1, PartnerCredit::count());
        $this->assertSame($credit->id, $over->fresh()->partner_credit_id);
        $this->assertSame(InvoiceOverpayment::STATUS_OPEN, $over->fresh()->status);

        // Seconde facture (mois suivant, même jour de semaine) à solder partiellement avec le crédit.
        $this->travelBack();
        $day = CarbonImmutable::parse($this->day, config('hl.timezone'))->addWeeks(5);
        $prepared = $this->bookings->prepare($this->spa, ['date' => $day->toDateString(), 'time' => '10:00', 'treatment' => $this->m->id, 'party' => 1]);
        $result = $this->submit($prepared);
        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $second = $result['booking'];
        $this->bookings->accept($second, 'partner');
        $this->travelTo($second->end_at->addHours(5)->toMutable());
        $this->bookings->complete($second, 'admin');
        $this->artisan('hl:billing-mark-legacy', ['--apply' => true])->assertSuccessful();
        $this->travelTo($day->startOfMonth()->addMonth()->day(6)->setTime(10, 0)->toMutable());
        $draft = app(InvoiceDrafter::class)->draft($this->partner(), $day->format('Y-m'));
        $this->assertNotNull($draft);
        $target = app(InvoiceIssuer::class)->issue($draft, $this->admin);
        $targetTotal = (float) $target->total_ttc;
        $prepaid = round($targetTotal / 2, 2);
        $this->pay($target, $prepaid);
        $this->assertLessThan(40, $targetTotal - $prepaid, 'le reste dû doit être inférieur au crédit pour tester le plafond du solde');

        $this->expectError('exceeds_credit', fn () => $credits->apply($credit, $target, $this->admin, 41, 'k1'));
        $this->expectError('exceeds_balance', fn () => $credits->apply($credit, $target, $this->admin, $targetTotal - $prepaid + 1, 'k2'));
        $this->expectError('amount_not_positive', fn () => $credits->apply($credit, $target, $this->admin, 0, 'k3'));
        $this->expectError('forbidden', fn () => $credits->apply($credit, $target, $this->admin->forceFill(['role' => 'partner']), 10, 'k4'));
        $this->admin->forceFill(['role' => 'admin']);
        $this->expectError('invoice_closed', fn () => $credits->apply($credit, $invoice, $this->admin, 10, 'k5'));

        $amount = round($targetTotal - $prepaid - 5, 2);
        $app1 = $credits->apply($credit, $target, $this->admin, $amount, 'apply-1');
        $this->assertSame($app1->id, $credits->apply($credit, $target, $this->admin, $amount, 'apply-1')->id, 'même clé = même opération');
        $target->refresh();
        $this->assertEqualsWithDelta($prepaid + $amount, $target->paid_amount, 0.001);
        $this->assertEqualsWithDelta(5, $target->balance, 0.001);
        $this->assertSame(Invoice::STATUS_PARTIALLY_PAID, $target->status);
        $payment = InvoicePayment::whereKey($app1->invoice_payment_id)->sole();
        $this->assertSame(InvoicePayment::METHOD_CREDIT, $payment->method);
        $this->assertNull($payment->receipt_number);
        $this->assertSame(1, InvoiceOverpayment::count(), 'une imputation ne crée jamais de trop-perçu');
        $credit->refresh();
        $this->assertEqualsWithDelta($amount, $credit->applied_amount, 0.001);
        $this->assertEqualsWithDelta(40 - $amount, $credit->remaining(), 0.001);
        $this->assertSame(PartnerCredit::STATUS_OPEN, $credit->status);
        try {
            app(PaymentRecorder::class)->reverse($payment, $this->admin, 'x', 'rv-1');
            $this->fail('credit application must not be reversible as a payment');
        } catch (PaymentException $e) {
            $this->assertSame('cannot_reverse_credit', $e->reason);
        }

        // Le reste est remboursé de façon déclarative ; le crédit et le trop-perçu d'origine se ferment.
        $this->expectError('reference_required', fn () => $credits->refund($credit, $this->admin, ['amount' => 15, 'refund_on' => CarbonImmutable::now()->toDateString(), 'method' => 'bank_transfer', 'reference' => ''], 'rf-0'));
        $this->expectError('refund_on_invalid', fn () => $credits->refund($credit, $this->admin, ['amount' => 15, 'refund_on' => CarbonImmutable::now()->addDay()->toDateString(), 'method' => 'bank_transfer', 'reference' => 'V'], 'rf-0'));
        $this->expectError('method_invalid', fn () => $credits->refund($credit, $this->admin, ['amount' => 15, 'refund_on' => CarbonImmutable::now()->toDateString(), 'method' => 'paypal', 'reference' => 'V'], 'rf-0'));
        $refund = $credits->refund($credit, $this->admin, ['amount' => 40 - $amount, 'refund_on' => CarbonImmutable::now()->toDateString(), 'method' => 'bank_transfer', 'reference' => 'VIR-2026-1', 'note' => 'Remboursé'], 'rf-1');
        $this->assertTrue($refund->isRefund());
        $this->assertSame('VIR-2026-1', $refund->refund_reference);
        $this->assertSame(0.0, $refund->credit_remaining_after);
        $credit->refresh();
        $this->assertSame(0.0, $credit->remaining());
        $this->assertSame(PartnerCredit::STATUS_APPLIED, $credit->status, 'usage majoritaire = imputation');
        $this->assertNotNull($credit->closed_at);
        $this->assertSame('applied', $over->fresh()->status);
        $this->expectError('credit_closed', fn () => $credits->refund($credit, $this->admin, ['amount' => 1, 'refund_on' => CarbonImmutable::now()->toDateString(), 'method' => 'cash', 'reference' => 'X'], 'rf-2'));
        $this->assertSame(2, PartnerCreditApplication::count());
        $this->assertSame(1, BillingNotification::where('event', 'credit_applied')->count());
        $this->assertSame(1, BillingNotification::where('event', 'credit_refunded')->count());

        // Ajout seul.
        try {
            $refund->update(['amount' => 1]);
            $this->fail('applications must be append-only');
        } catch (LogicException) {
        }
        try {
            $credit->update(['amount' => 1]);
            $this->fail('credit amount must be frozen');
        } catch (LogicException) {
        }
        try {
            $credit->delete();
            $this->fail('credits must not be deletable');
        } catch (LogicException) {
        }
    }

    public function test_schedule_registers_f4_commands(): void
    {
        $events = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command.' '.$e->expression.' '.($e->timezone ?? ''));
        $this->assertTrue($events->contains(fn ($c) => str_contains($c, 'hl:billing-credit-note-retry') && str_contains($c, '0 * * * *')));
        $this->assertTrue($events->contains(fn ($c) => str_contains($c, 'hl:billing-correction-reminders') && str_contains($c, '0 8 * * *') && str_contains($c, 'Africa/Casablanca')));
    }

    public function test_admin_screens_show_corrections_credit_notes_and_credits_and_partner_only_sees_their_own(): void
    {
        [$invoice, [$a]] = $this->issued();
        $total = (float) $invoice->total_ttc;
        $this->pay($invoice, $total + 10);
        $entry = $this->correction($a);
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(PostIssueCorrectionsPage::class)->assertSuccessful()->assertSee($a->reference)->assertSee($invoice->number);
        Livewire::test(ViewInvoice::class, ['record' => $invoice->id])->assertSuccessful()
            ->callAction('createCreditNote', ['entries' => [$entry->id], 'reason' => 'Remboursement client'])->assertHasNoActionErrors()->assertNotified();
        $note = CreditNote::sole();
        $this->assertTrue($note->isDraft());
        Livewire::test(ViewInvoice::class, ['record' => $invoice->id])->assertSee(__('billing.credit_note_status_draft'))
            ->callAction('issueCreditNote', arguments: ['note' => $note->id])->assertHasNoActionErrors()->assertNotified();
        $this->assertTrue($note->fresh()->isIssued());
        Livewire::test(ViewInvoice::class, ['record' => $invoice->id])->assertSee($note->fresh()->number)->assertSee(__('billing.credit_note_status_issued'));

        Livewire::test(OverpaymentsPage::class)->assertSuccessful()->callAction('openCredit', arguments: ['overpayment' => InvoiceOverpayment::sole()->id])->assertNotified();
        $this->assertSame(2, PartnerCredit::count());
        $fromOver = PartnerCredit::where('source', 'overpayment')->sole();
        Livewire::test(PartnerCreditsPage::class)->assertSuccessful()->assertSee(__('billing.credit_status_open'))
            ->callAction('refundCredit', ['idempotency_key' => 'ui-refund-1', 'amount' => 10, 'refund_on' => CarbonImmutable::now()->toDateString(), 'method' => 'bank_transfer', 'reference' => 'VIR-9'], ['credit' => $fromOver->id])
            ->assertHasNoActionErrors()->assertNotified();
        $this->assertSame(PartnerCredit::STATUS_REFUNDED, $fromOver->fresh()->status);
        $this->assertSame('refunded', InvoiceOverpayment::sole()->status);
        auth()->logout();

        // Partenaire : lecture de ses avoirs et crédits ; PDF d'un avoir étranger refusé.
        $this->actingAs($this->spa->partner->user);
        Filament::setCurrentPanel(Filament::getPanel('partner'));
        Filament::setTenant($this->spa, true);
        Livewire::test(Invoices::class)->assertSuccessful()->call('select', $invoice->id)->assertSee($note->fresh()->number)->assertSee(__('billing.credits'))->assertSee('VIR-9');
        $other = Partner::create(['user_id' => User::create(['name' => 'Autre', 'email' => 'autre@example.test', 'password' => 'secret-test', 'role' => 'partner'])->id, 'company_name' => 'Autre SARL', 'status' => 'approved']);
        CreditNote::whereKey($note->id)->update(['partner_id' => $other->id]);
        Livewire::test(Invoices::class)->call('select', $invoice->id)->callAction('creditNotePdf', arguments: ['note' => $note->id])->assertNotified(__('billing.pdf_corrupt'));
    }
}
