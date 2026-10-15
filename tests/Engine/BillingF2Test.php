<?php

namespace Tests\Engine;

use App\Domain\Billing\BillingGate;
use App\Domain\Billing\BillingSettings;
use App\Domain\Billing\DisputeException;
use App\Domain\Billing\DisputeService;
use App\Domain\Billing\InvoiceDrafter;
use App\Domain\Billing\InvoiceException;
use App\Domain\Billing\InvoiceIssuer;
use App\Domain\Billing\InvoicePdf;
use App\Filament\Admin\Resources\InvoiceResource\Pages\ListInvoices;
use App\Filament\Partner\Pages\Invoices;
use App\Jobs\GenerateInvoicePdf;
use App\Models\ActivityLog;
use App\Models\BillingNotification;
use App\Models\Booking;
use App\Models\CommissionDispute;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\LedgerEntry;
use App\Models\Partner;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use LogicException;
use RuntimeException;

/** Lot F2 : brouillons, émission verrouillée en deux étapes, PDF privé, régularisation des contestations acceptées. Base de test uniquement. */
class BillingF2Test extends BookingFlowTestCase
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

    private function qualify(): void
    {
        $this->artisan('hl:billing-mark-legacy', ['--apply' => true])->assertSuccessful();
    }

    /** Se place au jour donné du mois suivant la période (le 5 = premier jour d'émission). */
    private function travelToNextMonth(int $day): void
    {
        $this->travelTo($this->periodStart->addMonth()->day($day)->setTime(10, 0)->toMutable());
    }

    private function unlock(): void
    {
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
        $this->assertTrue($s->enabled());
    }

    private function partner(): Partner
    {
        return $this->spa->partner->fresh();
    }

    private function draft(): ?Invoice
    {
        return app(InvoiceDrafter::class)->draft($this->partner(), $this->period);
    }

    private function issue(Invoice $invoice): Invoice
    {
        return app(InvoiceIssuer::class)->issue($invoice, $this->admin);
    }

    /** Écriture corrective explicite (ce que ferait un arbitrage/ajustement Admin), jamais créée par la contestation elle-même. */
    private function reversal(Booking $booking, float $amount, ?CarbonImmutable $at = null): LedgerEntry
    {
        $commission = LedgerEntry::where('booking_id', $booking->id)->where('type', 'commission')->firstOrFail();

        return LedgerEntry::create(['booking_id' => $booking->id, 'booking_reference' => $booking->reference, 'partner_id' => $booking->spa->partner_id,
            'spa_id' => $booking->spa_id, 'type' => 'commission_reversal', 'amount' => -$amount, 'currency' => $booking->currency,
            'corrects_entry_id' => $commission->id, 'actor' => 'admin', 'note' => 'test', 'idempotency_key' => 'test:'.$booking->id.':'.uniqid(),
            'billing_scope' => LedgerEntry::SCOPE_BILLABLE, 'created_at' => $at ?? now()]);
    }

    public function test_drafts_are_idempotent_and_never_created_for_zero_total(): void
    {
        $this->completed();
        $this->qualify();
        $this->travelToNextMonth(1);
        $this->artisan('hl:billing-drafts', ['--period' => $this->period])->assertSuccessful();
        $this->artisan('hl:billing-drafts', ['--period' => $this->period])->assertSuccessful();
        $this->assertSame(1, Invoice::count());
        $invoice = Invoice::first();
        $this->assertTrue($invoice->isDraft());
        $this->assertNull($invoice->number);
        $this->assertNull($invoice->issued_at);
        $this->assertSame(1, $invoice->lines_count);
        $this->assertGreaterThan(0, $invoice->total_ttc);
        $this->assertSame(1, InvoiceLine::count());
        $this->assertSame(1, ActivityLog::where('action', 'billing.invoice_drafted')->count());

        // Mois sans écriture facturable : aucun brouillon ; un mois antérieur au démarrage : aucun brouillon.
        $this->assertNull(app(InvoiceDrafter::class)->draft($this->partner(), $this->periodStart->addMonths(3)->format('Y-m')));
        $this->assertSame([], app(InvoiceDrafter::class)->draftAll($this->periodStart->subYear()->format('Y-m')));
        // Contre-écriture totale enregistrée après la clôture : reportée au mois suivant, le brouillon du mois reste intact.
        $this->reversal(Booking::firstOrFail(), $invoice->total_ttc);
        $this->assertSame($invoice->total_ttc, $this->draft()->total_ttc);
        $this->assertNull(app(InvoiceDrafter::class)->draft($this->partner(), $this->periodStart->addMonth()->format('Y-m')), 'negative statement: no invoice');
        // Contre-écriture datée dans le mois : total 0, le brouillon est retiré, l'historique du grand livre reste.
        $this->reversal(Booking::firstOrFail(), $invoice->total_ttc, $this->periodStart->endOfMonth()->subHour());
        $this->assertNull($this->draft());
        $this->assertSame(0, Invoice::count());
        $this->assertSame(3, LedgerEntry::whereIn('type', LedgerEntry::COMMISSION_TYPES)->count());
    }

    public function test_issue_is_blocked_while_billing_disabled(): void
    {
        $this->completed();
        $this->qualify();
        $this->travelToNextMonth(6);
        $invoice = $this->draft();
        $blockers = app(InvoiceIssuer::class)->blockers($invoice);
        $this->assertContains('billing_disabled', $blockers);
        $this->assertContains('vat_mode_pending', $blockers);
        try {
            $this->issue($invoice);
            $this->fail('issue must be refused');
        } catch (InvoiceException $e) {
            $this->assertSame('blocked', $e->reason);
            $this->assertContains('billing_disabled', $e->blockers);
        }
        $this->assertTrue($invoice->fresh()->isDraft());
        $this->assertSame(0, Invoice::issued()->count());
    }

    public function test_issue_blocked_before_day_5_and_while_an_accepted_dispute_awaits_correction(): void
    {
        $a = $this->completed('10:00');
        $b = $this->completed('12:00');
        $this->qualify();
        $this->unlock();
        $this->travelToNextMonth(3);
        $invoice = $this->draft();
        $this->assertSame(['issue_day_not_reached'], app(InvoiceIssuer::class)->blockers($invoice));

        $this->travelToNextMonth(6);
        $this->assertSame([], app(InvoiceIssuer::class)->blockers($invoice));
        try {
            app(InvoiceIssuer::class)->issue($invoice, $this->partner()->user);
            $this->fail('a partner can never issue an invoice');
        } catch (InvoiceException $e) {
            $this->assertSame('forbidden', $e->reason);
        }
        $this->assertTrue($invoice->fresh()->isDraft());

        // Contestation acceptée sur B : seule B sort du relevé... puis l'acceptation « à régulariser » bloque toute émission.
        $dispute = app(DisputeService::class)->open($this->partner(), $b, $this->partner()->user, 'amount_incorrect', 'Montant erroné');
        app(DisputeService::class)->decide($dispute, $this->admin, 'accepted', 'Erreur confirmée');
        $dispute->refresh();
        $this->assertTrue($dispute->needsCorrection());
        $this->assertSame(CommissionDispute::CORRECTION_PENDING, $dispute->correction_status);
        $this->assertSame(2, LedgerEntry::whereIn('type', LedgerEntry::COMMISSION_TYPES)->count(), 'acceptance never touches the ledger');
        $this->assertContains('corrections_pending', app(InvoiceIssuer::class)->blockers($invoice));
        try {
            $this->issue($invoice);
            $this->fail('issue must be refused while a correction is pending');
        } catch (InvoiceException $e) {
            $this->assertContains('corrections_pending', $e->blockers);
        }

        // Régularisation : écriture corrective explicite rattachée par l'Admin.
        $entry = $this->reversal($b, 10);
        $settled = app(DisputeService::class)->settle($dispute, $this->admin, $entry, 'Contre-écriture passée');
        $this->assertSame(CommissionDispute::CORRECTION_SETTLED, $settled->correction_status);
        $this->assertSame($entry->id, $settled->correction_entry_id);
        $this->assertSame($this->admin->id, $settled->settled_by);
        $this->assertSame(1, ActivityLog::where('action', 'billing.dispute_settled')->count());
        $this->assertSame(0, CommissionDispute::pendingCorrection()->count());
        $this->assertSame([], app(InvoiceIssuer::class)->blockers($invoice->fresh()));

        $issued = $this->issue($invoice->fresh());
        $this->assertSame(2, $issued->lines_count, 'commission A and B (decided dispute no longer excludes B); the reversal recorded after month end is carried to the next period');
        $this->assertSame(0, InvoiceLine::where('ledger_entry_id', $entry->id)->count(), 'late reversal not invoiced in the closed period');
    }

    public function test_settlement_requires_an_explicit_corrective_entry_for_the_same_booking(): void
    {
        $a = $this->completed('10:00');
        $b = $this->completed('12:00');
        $this->qualify();
        $this->travelToNextMonth(2);
        $before = $this->reversal($a, 1);
        $dispute = app(DisputeService::class)->open($this->partner(), $a, $this->partner()->user, 'other', 'Test');
        $this->travelToNextMonth(3);
        app(DisputeService::class)->decide($dispute, $this->admin, 'accepted', 'OK');
        $dispute->refresh();
        $otherBooking = $this->reversal($b, 1);
        $service = app(DisputeService::class);
        foreach ([$otherBooking, $before, LedgerEntry::where('booking_id', $a->id)->where('type', 'commission')->firstOrFail()] as $bad) {
            try {
                $service->settle($dispute, $this->admin, $bad);
                $this->fail('invalid correction entry must be refused');
            } catch (DisputeException $e) {
                $this->assertSame('correction_entry_invalid', $e->reason);
            }
        }
        $this->assertTrue($dispute->fresh()->needsCorrection());
        $good = $this->reversal($a, 2);
        try {
            $service->settle($dispute, $this->partner()->user, $good);
            $this->fail('a partner can never settle a dispute');
        } catch (DisputeException $e) {
            $this->assertSame('forbidden', $e->reason);
        }
        try {
            $service->decide($dispute, $this->partner()->user, 'rejected', 'x');
            $this->fail('a partner can never decide a dispute');
        } catch (DisputeException $e) {
            $this->assertSame('forbidden', $e->reason);
        }
        $service->settle($dispute, $this->admin, $good);
        $this->assertFalse($dispute->fresh()->needsCorrection());
        try {
            $service->settle($dispute->fresh(), $this->admin, $this->reversal($a, 1));
            $this->fail('a settled dispute cannot be settled twice');
        } catch (DisputeException $e) {
            $this->assertSame('not_pending_correction', $e->reason);
        }
        // Une écriture déjà rattachée ne peut pas régulariser une autre contestation.
        $d2 = $service->open($this->partner(), $b, $this->partner()->user, 'other', 'Test 2');
        $service->decide($d2, $this->admin, 'accepted', 'OK');
        try {
            $service->settle($d2->fresh(), $this->admin, $good);
            $this->fail('entry already linked');
        } catch (DisputeException $e) {
            $this->assertSame('correction_entry_invalid', $e->reason);
        }
        // Un rejet ne crée aucune correction à suivre.
        $d3 = CommissionDispute::create($dispute->only(['partner_id', 'spa_id', 'booking_id', 'ledger_entry_id', 'period', 'kind', 'reason_code']) + ['comment' => 'x', 'status' => 'open']);
        $service->decide($d3, $this->admin, 'rejected', 'Non');
        $this->assertNull($d3->fresh()->correction_status);
    }

    public function test_open_dispute_excludes_only_its_line_and_the_entry_is_carried_to_the_next_invoice_once(): void
    {
        $a = $this->completed('10:00');
        $b = $this->completed('12:00');
        $this->qualify();
        $this->unlock();
        $this->travelToNextMonth(2);
        $dispute = app(DisputeService::class)->open($this->partner(), $a, $this->partner()->user, 'other', 'En attente');
        $this->travelToNextMonth(6);
        $invoice = $this->draft();
        $this->assertSame(1, $invoice->lines_count);
        $this->assertSame($b->id, $invoice->lines()->first()->booking_id);
        $issued = $this->issue($invoice);
        $this->assertSame(Invoice::STATUS_ISSUED, $issued->status);
        $this->assertMatchesRegularExpression('/^HL-\d{4}-000001$/', $issued->number);
        $this->assertTrue($issued->issued_at->isSameDay(now()));
        $this->assertSame(now()->addDays(15)->toDateString(), $issued->due_at->toDateString());
        $this->assertSame('HL company_name', $issued->issuer_snapshot['company_name']);
        $this->assertSame('001234567000089', $issued->partner_snapshot['ice']);
        $this->assertSame(1, ActivityLog::where('action', 'billing.invoice_issued')->count());

        // Rejet après émission : la commission de A redevient facturable, reportée sur le mois suivant avec sa période d'origine.
        app(DisputeService::class)->decide($dispute, $this->admin, 'rejected', 'Non fondée');
        $this->assertSame($issued->id, $this->draft()->id, 'the issued period never gets a second invoice');
        $this->assertSame(1, Invoice::count());
        $next = $this->periodStart->addMonth();
        $this->travelTo($next->addMonth()->day(6)->setTime(10, 0)->toMutable());
        $carried = app(InvoiceDrafter::class)->draft($this->partner(), $next->format('Y-m'));
        $this->assertNotNull($carried);
        $this->assertSame(1, $carried->lines_count);
        $line = $carried->lines()->first();
        $this->assertSame($a->id, $line->booking_id);
        $this->assertSame($this->period, $line->origin_period);
        $this->assertTrue($line->carried);
        $second = $this->issue($carried);
        $this->assertMatchesRegularExpression('/-000002$/', $second->number);
        $this->assertSame(2, InvoiceLine::count(), 'each ledger entry is invoiced exactly once');
        $this->assertNull(app(InvoiceDrafter::class)->draft($this->partner(), $next->addMonth()->format('Y-m')));
    }

    public function test_issued_invoices_are_immutable_and_an_entry_cannot_be_invoiced_twice(): void
    {
        $this->completed();
        $this->qualify();
        $this->unlock();
        $this->travelToNextMonth(6);
        $issued = $this->issue($this->draft());
        $line = $issued->lines()->firstOrFail();

        foreach ([['subtotal_ht' => 1], ['number' => 'X'], ['issued_at' => now()->subYear()], ['partner_id' => 999]] as $change) {
            try {
                $issued->fresh()->update($change);
                $this->fail('issued invoice must be immutable: '.json_encode($change));
            } catch (LogicException) {
            }
        }
        try {
            $line->update(['amount' => 1]);
            $this->fail('issued lines must be immutable');
        } catch (LogicException) {
        }
        try {
            $issued->fresh()->delete();
            $this->fail('issued invoice cannot be deleted');
        } catch (LogicException) {
        }
        $issued->fresh()->update(['notes' => 'ok']);

        $other = Invoice::create(['partner_id' => $issued->partner_id, 'period' => '2031-01', 'status' => 'draft', 'currency' => 'MAD']);
        try {
            $other->lines()->create(['ledger_entry_id' => $line->ledger_entry_id, 'entry_type' => 'commission', 'description' => 'dup', 'amount' => 1, 'origin_period' => '2031-01']);
            $this->fail('ledger entry must belong to a single invoice line');
        } catch (UniqueConstraintViolationException) {
        }
        try {
            $this->issue($issued->fresh());
            $this->fail('cannot issue twice');
        } catch (InvoiceException $e) {
            $this->assertSame('already_issued', $e->reason);
        }
        $this->assertSame(1, Invoice::issued()->count());
    }

    public function test_pdf_not_written_on_disk_is_a_failure_not_a_ready_pdf_with_empty_hash(): void
    {
        $this->completed();
        $this->qualify();
        $this->unlock();
        $this->travelToNextMonth(6);
        Queue::fake([GenerateInvoicePdf::class]);
        $issued = $this->issue($this->draft());
        $path = app(InvoicePdf::class)->path($issued);
        Storage::disk('local')->makeDirectory($path);

        app()->call([new GenerateInvoicePdf($issued->id), 'handle']);
        $issued->refresh();
        $this->assertSame(Invoice::PDF_FAILED, $issued->pdf_status);
        $this->assertNull($issued->pdf_sha256);
        $this->assertStringContainsString(__('billing.invoice_error.pdf_write_failed'), (string) $issued->pdf_error);
        $this->assertFalse(app(InvoicePdf::class)->verify($issued));
        $this->assertSame(0, BillingNotification::where('event', 'invoice_issued')->count());
        $this->assertSame(1, BillingNotification::where('event', 'invoice_pdf_failed')->count());
        $this->assertSame(1, Invoice::issued()->count());

        Storage::disk('local')->deleteDirectory($path);
        app()->call([new GenerateInvoicePdf($issued->id), 'handle']);
        $issued->refresh();
        $this->assertSame(Invoice::PDF_READY, $issued->pdf_status);
        $this->assertNotSame(hash('sha256', ''), $issued->pdf_sha256);
        $this->assertTrue(app(InvoicePdf::class)->verify($issued));
        $this->assertSame(1, BillingNotification::where('event', 'invoice_issued')->count());
    }

    public function test_pdf_failing_after_ready_clears_the_old_path_and_hash(): void
    {
        $this->completed();
        $this->qualify();
        $this->unlock();
        $this->travelToNextMonth(6);
        Queue::fake([GenerateInvoicePdf::class]);
        $issued = $this->issue($this->draft());
        app()->call([new GenerateInvoicePdf($issued->id), 'handle']);
        $issued->refresh();
        $this->assertSame(Invoice::PDF_READY, $issued->pdf_status);
        $oldPath = $issued->pdf_path;
        $this->assertSame(1, BillingNotification::where('event', 'invoice_issued')->count());

        Storage::disk('local')->delete($oldPath);
        Storage::disk('local')->makeDirectory($oldPath);
        $issued->update(['pdf_status' => Invoice::PDF_FAILED]);
        app()->call([new GenerateInvoicePdf($issued->id), 'handle']);
        $issued->refresh();
        $this->assertSame(Invoice::PDF_FAILED, $issued->pdf_status);
        $this->assertNull($issued->pdf_path);
        $this->assertNull($issued->pdf_sha256);
        $this->assertFalse(app(InvoicePdf::class)->verify($issued));
        $this->assertSame(1, BillingNotification::where('event', 'invoice_issued')->count());
        $this->assertSame(1, BillingNotification::where('event', 'invoice_pdf_failed')->count());
        $this->assertSame(1, Invoice::issued()->count());

        Storage::disk('local')->deleteDirectory($oldPath);
        app()->call([new GenerateInvoicePdf($issued->id), 'handle']);
        $issued->refresh();
        $this->assertSame(Invoice::PDF_READY, $issued->pdf_status);
        $this->assertSame($oldPath, $issued->pdf_path);
        $this->assertNotNull($issued->pdf_sha256);
        $this->assertSame(hash('sha256', (string) Storage::disk('local')->get($oldPath)), $issued->pdf_sha256);
        $this->assertTrue(app(InvoicePdf::class)->verify($issued));
        $this->assertSame(1, BillingNotification::where('event', 'invoice_issued')->count());
    }

    public function test_pdf_failure_never_duplicates_the_invoice_and_partner_is_notified_once_when_ready(): void
    {
        $this->completed();
        $this->qualify();
        $this->unlock();
        $this->travelToNextMonth(6);
        Queue::fake([GenerateInvoicePdf::class]);
        $issued = $this->issue($this->draft());
        Queue::assertPushed(GenerateInvoicePdf::class, 1);
        $this->assertSame(Invoice::PDF_PENDING, $issued->pdf_status);

        $broken = $this->createMock(InvoicePdf::class);
        $broken->method('verify')->willReturn(false);
        $broken->method('generate')->willThrowException(new RuntimeException('dompdf down'));
        $this->app->instance(InvoicePdf::class, $broken);
        app()->call([new GenerateInvoicePdf($issued->id), 'handle']);
        $issued->refresh();
        $this->assertSame(Invoice::PDF_FAILED, $issued->pdf_status);
        $this->assertSame(1, $issued->pdf_attempts);
        $this->assertStringContainsString('dompdf down', $issued->pdf_error);
        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, BillingNotification::where('event', 'invoice_pdf_failed')->where('recipient', 'alerts@example.test')->count());
        $this->assertSame(0, BillingNotification::where('event', 'invoice_issued')->count(), 'partner not notified until PDF ready');

        $this->app->forgetInstance(InvoicePdf::class);
        Queue::fake([GenerateInvoicePdf::class]);
        $this->artisan('hl:billing-pdf-retry')->assertSuccessful();
        Queue::assertPushed(GenerateInvoicePdf::class, 1);
        app()->call([new GenerateInvoicePdf($issued->id), 'handle']);
        $issued->refresh();
        $this->assertSame(Invoice::PDF_READY, $issued->pdf_status);
        $this->assertSame(2, $issued->pdf_attempts);
        $this->assertTrue(Storage::disk('local')->exists($issued->pdf_path));
        $this->assertStringStartsWith('invoices/', $issued->pdf_path);
        $this->assertStringStartsWith('%PDF', (string) Storage::disk('local')->get($issued->pdf_path));
        $this->assertTrue(app(InvoicePdf::class)->verify($issued));
        $this->assertSame(1, BillingNotification::where('event', 'invoice_issued')->where('recipient', 'compta@example.test')->count());

        // Rejeu : rien de régénéré, aucune seconde notification, aucune seconde facture.
        app()->call([new GenerateInvoicePdf($issued->id), 'handle']);
        $this->artisan('hl:billing-pdf-retry')->assertSuccessful();
        $this->assertSame(1, BillingNotification::where('event', 'invoice_issued')->count());
        $this->assertSame(1, Invoice::count());
        $this->assertSame(2, $issued->fresh()->pdf_attempts);

        // Fichier altéré : empreinte invalide, PDF non servi.
        Storage::disk('local')->put($issued->pdf_path, 'tampered');
        $this->assertFalse(app(InvoicePdf::class)->verify($issued->fresh()));
    }

    public function test_admin_list_tabs_and_filters_render_for_drafts_and_issued_invoices(): void
    {
        $this->completed();
        $this->qualify();
        $this->unlock();
        $this->travelToNextMonth(6);
        $draft = $this->draft();
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ListInvoices::class)->assertOk()->assertCanSeeTableRecords([$draft])
            ->set('activeTab', 'draft')->assertOk()->assertCanSeeTableRecords([$draft])
            ->set('activeTab', 'issued')->assertOk()->assertCanNotSeeTableRecords([$draft])
            ->set('activeTab', 'paid')->assertOk()->assertCanNotSeeTableRecords([$draft]);
        $issued = $this->issue($draft);
        Livewire::test(ListInvoices::class)->set('activeTab', 'issued')->assertOk()->assertCanSeeTableRecords([$issued])
            ->set('activeTab', 'draft')->assertCanNotSeeTableRecords([$issued])
            ->set('activeTab', 'all')->filterTable('overdue')->assertOk()->assertCanNotSeeTableRecords([$issued])
            ->filterTable('period', $this->period)->assertOk();
        $this->get('/admin/invoices/'.$issued->id)->assertOk()->assertSee($issued->number);
    }

    public function test_partner_page_is_tenant_bound_and_hides_drafts(): void
    {
        $this->completed();
        $this->qualify();
        $this->unlock();
        $this->travelToNextMonth(6);
        $draft = $this->draft();
        $this->actingAs($this->partner()->user);
        Filament::setCurrentPanel(Filament::getPanel('partner'));
        Filament::setTenant($this->spa);
        Livewire::test(Invoices::class)->assertSee(__('billing.no_invoices'))->assertDontSee($this->period.'</td>', false);

        Queue::fake([GenerateInvoicePdf::class]);
        $issued = $this->issue($draft);
        Livewire::test(Invoices::class)->assertSee($issued->number)->call('select', $issued->id)->assertSee(__('billing.pdf_pending'))->assertDontSee(__('billing.download_pdf'));
        app()->call([new GenerateInvoicePdf($issued->id), 'handle']);
        Livewire::test(Invoices::class)->assertSee($issued->number)->call('select', $issued->id)->assertSee(__('billing.download_pdf'))
            ->callAction('pdf')->assertFileDownloaded($issued->number.'.pdf');

        // Autre entité juridique : la page ne voit ni la liste ni la facture par identifiant.
        $other = Partner::create(['company_name' => 'Autre SARL', 'status' => 'pending', 'user_id' => User::create(['name' => 'P2', 'email' => 'p2@example.test', 'password' => 'secret-test', 'role' => 'partner', 'email_verified_at' => now()])->id]);
        $foreign = Invoice::create(['partner_id' => $other->id, 'period' => $this->period, 'status' => 'draft', 'currency' => 'MAD']);
        $page = new Invoices;
        $page->selected = $foreign->id;
        $this->assertNull($page->invoice());
        $page->selected = $issued->id;
        $this->assertSame($issued->id, $page->invoice()?->id);
        $this->assertSame(1, $page->invoices()->count());

        // Accès direct au disque privé : jamais servi sans contrôle.
        auth()->logout();
        $this->assertContains($this->get('/storage/'.$issued->pdf_path)->getStatusCode(), [403, 404]);
    }
}
