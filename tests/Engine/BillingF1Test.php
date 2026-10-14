<?php

namespace Tests\Engine;

use App\Domain\Billing\BillingGate;
use App\Domain\Billing\BillingNotifier;
use App\Domain\Billing\BillingSettings;
use App\Domain\Billing\DisputeException;
use App\Domain\Billing\DisputeService;
use App\Domain\Billing\StatementBuilder;
use App\Domain\Booking\CancellationService;
use App\Filament\Partner\Pages\CommissionStatement;
use App\Filament\Partner\Resources\CommissionDisputeResource;
use App\Jobs\SendBillingNotification;
use App\Mail\BillingMail;
use App\Models\ActivityLog;
use App\Models\BillingNotification;
use App\Models\BillingSetting;
use App\Models\Booking;
use App\Models\CancellationRequest;
use App\Models\CommissionDispute;
use App\Models\LedgerEntry;
use App\Models\Partner;
use App\Models\Spa;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Mockery;
use RuntimeException;

/** Lot F1 : relevé provisoire, contestations, notifications financières. Base de test uniquement, aucune émission. */
class BillingF1Test extends BookingFlowTestCase
{
    private User $admin;

    private string $period;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'secret-test', 'role' => 'admin', 'email_verified_at' => now()]);
        $this->period = CarbonImmutable::parse($this->day, config('hl.timezone'))->format('Y-m');
        $this->actingAs($this->admin);
        app(BillingSettings::class)->set('start_period', CarbonImmutable::now()->subMonthNoOverflow()->format('Y-m'));
        auth()->logout();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function confirmed(string $time = '10:00'): Booking
    {
        $result = $this->submit($this->intent($time, ['treatment' => $this->m->id, 'party' => 1]));
        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $booking = $result['booking'];
        $this->bookings->accept($booking, 'partner');

        return $booking->fresh();
    }

    private function completed(string $time = '10:00'): Booking
    {
        $booking = $this->confirmed($time);
        $this->travelTo($booking->end_at->addHours(5)->toMutable());
        $this->bookings->complete($booking, 'admin');
        $this->travelBack();

        return $booking->fresh();
    }

    /** Les relevés se consultent après les prestations : on se place deux jours après le jour testé. */
    private function viewAfter(int $days = 2): void
    {
        $this->travelTo(CarbonImmutable::parse($this->day, config('hl.timezone'))->addDays($days)->setTime(10, 0)->toMutable());
    }

    private function qualify(): void
    {
        $this->artisan('hl:billing-mark-legacy', ['--apply' => true])->assertSuccessful();
    }

    private function statement(?Partner $partner = null, ?string $period = null, ?CarbonImmutable $now = null)
    {
        return app(StatementBuilder::class)->build($partner ?? $this->spa->partner, $period ?? $this->period, $now);
    }

    private function partnerUser(): User
    {
        return $this->spa->partner->user;
    }

    public function test_schema_is_additive_and_no_invoice_exists(): void
    {
        $this->assertTrue(Schema::hasColumn('ledger_entries', 'spa_id'));
        $this->assertTrue(Schema::hasTable('commission_disputes'));
        $this->assertTrue(Schema::hasTable('billing_notifications'));
        $this->assertFalse(Schema::hasTable('invoices'));
        $this->assertFalse(app(BillingSettings::class)->enabled());
        $this->assertFalse(app(BillingGate::class)->canIssue());
    }

    public function test_statement_lists_completed_commissions_by_spa_and_ignores_unqualified_offline_and_free_cancellation(): void
    {
        $done = $this->completed('10:00');
        $this->assertSame(60.0, $done->commissionDue());
        $entry = LedgerEntry::where('booking_id', $done->id)->firstOrFail();
        $this->assertSame($this->spa->id, $entry->spa_id);

        $free = $this->confirmed('12:00');
        $this->bookings->cancel($free, 'client');
        $this->assertSame(0, LedgerEntry::where('booking_id', $free->id)->count(), 'Annulation gratuite : aucune commission');

        $offline = $this->bookings->bookOffline($this->spa, $this->at('17:00'), [['treatment' => $this->m->id, 'party' => 1]], $this->customer, 'phone', $this->partnerUser()->id);
        $this->travelTo($offline->end_at->addHours(5)->toMutable());
        $this->bookings->complete($offline, 'partner');
        $this->travelBack();
        $this->assertSame(0.0, $offline->fresh()->commissionDue());

        $this->viewAfter();
        // Non qualifiée : rien n'est facturable.
        $this->assertTrue($this->statement()->isEmpty());

        $this->qualify();
        $s = $this->statement();
        $this->assertSame(60.0, $s->total);
        $this->assertCount(1, $s->lines());
        $this->assertSame($done->reference, $s->lines()[0]['reference']);
        $this->assertSame($this->period, $s->lines()[0]['origin_period']);
        $this->assertFalse($s->lines()[0]['carried']);
        $this->assertSame([$this->spa->id], array_keys($s->spas));
        $this->assertSame([], $s->excluded);
        $this->assertContains('billing_disabled', $s->blockers);
    }

    public function test_no_show_with_fee_included_without_fee_excluded_and_corrections_follow_the_booking(): void
    {
        $withFee = $this->confirmed('10:00');
        $noFee = $this->confirmed('12:00');
        $this->travelTo($withFee->start_at->addMinutes(30)->toMutable());
        $this->bookings->noShow($withFee, 'partner', true);
        $this->travelTo($noFee->start_at->addMinutes(30)->toMutable());
        $this->bookings->noShow($noFee, 'partner', false);
        $this->viewAfter();
        $this->qualify();

        $s = $this->statement();
        $refs = array_column($s->lines(), 'reference');
        $this->assertContains($withFee->reference, $refs);
        $this->assertNotContains($noFee->reference, $refs);
        $this->assertSame($withFee->fresh()->commissionDue(), $s->total);
        $this->assertGreaterThan(0, $s->total);

        // Correction (remboursement intégral) : contre-écriture liée, même réservation, solde 0 — jamais de suppression.
        $this->bookings->setPaymentStatus($withFee->fresh(), 'admin', 'refunded');
        $this->qualify();
        $entries = LedgerEntry::where('booking_id', $withFee->id)->orderBy('id')->get();
        $this->assertCount(2, $entries);
        $this->assertSame('commission_reversal', $entries[1]->type);
        $this->assertSame('billable', $entries[1]->billing_scope);
        $s = $this->statement();
        $this->assertCount(1, $s->lines());
        $this->assertSame(0.0, $s->lines()[0]['amount']);
        $this->assertCount(2, $s->lines()[0]['entries']);
    }

    public function test_legacy_entries_and_their_corrections_stay_out_of_statements(): void
    {
        $done = $this->completed('10:00');
        $this->actingAs($this->admin);
        // Démarrage postérieur à l'acquisition : la commission est legacy.
        app(BillingSettings::class)->set('start_period', CarbonImmutable::parse($this->day)->addMonths(2)->format('Y-m'));
        auth()->logout();
        $this->qualify();
        $this->assertSame('legacy', LedgerEntry::where('booking_id', $done->id)->value('billing_scope'));
        $this->viewAfter();
        $this->bookings->setPaymentStatus($done->fresh(), 'admin', 'refunded');
        $this->qualify();
        $this->assertSame(['legacy', 'legacy'], LedgerEntry::where('booking_id', $done->id)->orderBy('id')->pluck('billing_scope')->all());
        foreach ([$this->period, CarbonImmutable::parse($this->day)->addMonths(2)->format('Y-m')] as $p) {
            $this->assertTrue($this->statement(null, $p)->isEmpty(), $p);
        }
    }

    public function test_late_entry_is_carried_to_the_next_statement_with_its_origin_period(): void
    {
        $booking = $this->confirmed('10:00');
        $origin = CarbonImmutable::parse($this->day, config('hl.timezone'));
        $nextMonth = $origin->addMonthNoOverflow()->startOfMonth()->addDays(1)->setTime(9, 0);
        $this->travelTo($nextMonth->toMutable());
        $this->bookings->complete($booking, 'admin'); // acquise après la clôture du mois de prestation
        $this->qualify();

        $closedMonth = $this->statement(null, $origin->format('Y-m'), $nextMonth);
        $this->assertTrue($closedMonth->isEmpty(), 'Acquise après clôture : pas dans le relevé du mois d’origine');
        $this->assertTrue($closedMonth->closed);

        $next = $this->statement(null, $nextMonth->format('Y-m'), $nextMonth);
        $this->assertCount(1, $next->lines());
        $this->assertSame($origin->format('Y-m'), $next->lines()[0]['origin_period']);
        $this->assertTrue($next->lines()[0]['carried']);
        $this->assertFalse($next->closed);
    }

    public function test_open_arbitration_is_excluded_until_decision(): void
    {
        $booking = $this->confirmed('10:00');
        app(CancellationService::class)->request($booking, $this->partnerUser(), 'Fermeture exceptionnelle de l’établissement ce jour-là');
        $this->travelTo($booking->end_at->addHours(5)->toMutable());
        $this->bookings->completePast();
        $this->assertTrue($booking->fresh()->needsArbitration());
        $this->assertSame(0, LedgerEntry::where('booking_id', $booking->id)->count(), 'Demande ouverte : pas de clôture ni de commission automatique');
        $this->viewAfter();
        $this->qualify();
        $this->assertTrue($this->statement()->isEmpty());

        app(CancellationService::class)->decide($booking->fresh()->pendingCancellationRequest, $this->admin, 'refused', 'Prestation réalisée, annulation refusée');
        $this->qualify();
        $this->assertFalse($booking->fresh()->needsArbitration());
        $this->assertSame(60.0, $this->statement()->total);

        // Garde-fou : une demande d’annulation redevenue « en attente » sur une prestation déjà commissionnée retire la ligne sans toucher au grand livre.
        CancellationRequest::create(['booking_id' => $booking->id, 'spa_id' => $booking->spa_id, 'requested_by' => $this->partnerUser()->id, 'reason' => 'Nouvel élément transmis après coup', 'status' => 'pending']);
        $s = $this->statement();
        $this->assertSame(0.0, $s->total);
        $this->assertSame('arbitration', $s->excluded[0]['exclusion']);
        $this->assertSame(60.0, $s->excludedTotal);
        $this->assertSame(1, LedgerEntry::where('booking_id', $booking->id)->count());
    }

    public function test_dispute_excludes_only_its_booking_never_touches_ledger_and_cannot_be_opened_twice(): void
    {
        Queue::fake();
        $a = $this->completed('10:00');
        $b = $this->completed('12:00');
        $this->qualify();
        $this->viewAfter();
        $ledgerBefore = LedgerEntry::orderBy('id')->get()->toArray();
        $partner = $this->spa->partner;

        $dispute = app(DisputeService::class)->open($partner, $a, $this->partnerUser(), 'amount_incorrect', 'Le client a payé 300, pas 400.', null, CarbonImmutable::parse($this->day)->addMonthNoOverflow()->startOfMonth()->addDays(2));
        $this->assertSame('dispute', $dispute->kind);
        $this->assertSame('open', $dispute->status);
        $this->assertSame($a->id, $dispute->open_booking_id);
        $this->assertSame($this->period, $dispute->period);

        $s = $this->statement();
        $this->assertSame([$b->reference], array_column($s->lines(), 'reference'));
        $this->assertSame(60.0, $s->total);
        $this->assertSame('disputed', $s->excluded[0]['exclusion']);
        $this->assertSame($dispute->id, $s->excluded[0]['dispute_id']);
        $this->assertSame($ledgerBefore, LedgerEntry::orderBy('id')->get()->toArray(), 'Le grand livre est intact');

        try {
            app(DisputeService::class)->open($partner, $a, $this->partnerUser(), 'other', 'Encore');
            $this->fail('double contestation');
        } catch (DisputeException $e) {
            $this->assertSame('already_open', $e->reason);
        }
        $this->assertSame(1, CommissionDispute::count());

        // Notification admin unique, traçable, mise en file après validation de la transaction.
        $n = BillingNotification::where('event', 'dispute_opened')->get();
        $this->assertCount(1, $n);
        $this->assertSame('admin', $n[0]->audience);
        $this->assertSame(config('hl.alert_email'), $n[0]->recipient);
        $this->assertSame('queued', $n[0]->status);
        Queue::assertPushed(SendBillingNotification::class, 1);
        $this->assertNull(app(BillingNotifier::class)->dispatch($dispute, 'dispute_opened', 'admin', 'x@example.test'), 'Clé de déduplication');
        $this->assertSame(1, BillingNotification::count());

        $log = ActivityLog::where('action', 'billing.dispute_opened')->first();
        $this->assertNotNull($log);
        $this->assertSame($a->reference, $log->properties['booking']);
        $this->assertTrue($a->events()->where('type', 'commission:disputed')->exists());
    }

    public function test_dispute_after_window_is_a_correction_request_that_excludes_nothing(): void
    {
        $a = $this->completed('10:00');
        $this->qualify();
        $late = CarbonImmutable::parse($this->day, config('hl.timezone'))->addMonthNoOverflow()->startOfMonth()->addDays(6);
        $d = app(DisputeService::class)->open($this->spa->partner, $a, $this->partnerUser(), 'other', 'Hors fenêtre', null, $late);
        $this->assertSame('correction_request', $d->kind);
        $s = $this->statement(null, null, $late);
        $this->assertCount(1, $s->lines());
        $this->assertSame([], $s->excluded);
        $this->assertFalse($s->disputeWindowOpen($late));
    }

    public function test_offline_and_other_partner_bookings_cannot_be_disputed(): void
    {
        $this->qualify();
        $offline = $this->bookings->bookOffline($this->spa, $this->at('17:00'), [['treatment' => $this->m->id, 'party' => 1]], $this->customer, 'phone', $this->partnerUser()->id);
        try {
            app(DisputeService::class)->open($this->spa->partner, $offline, $this->partnerUser(), 'other', 'x');
            $this->fail();
        } catch (DisputeException $e) {
            $this->assertSame('offline_booking', $e->reason);
        }
        $a = $this->completed('10:00');
        $this->qualify();
        $otherUser = User::create(['name' => 'Autre', 'email' => 'autre@example.test', 'password' => 'secret-test', 'role' => 'partner', 'email_verified_at' => now()]);
        $other = Partner::create(['user_id' => $otherUser->id, 'company_name' => 'Autre SARL', 'status' => 'approved']);
        try {
            app(DisputeService::class)->open($other, $a, $otherUser, 'other', 'x');
            $this->fail();
        } catch (DisputeException $e) {
            $this->assertSame('not_owner', $e->reason);
        }
        $this->assertTrue($this->statement($other)->isEmpty());
        $this->assertSame(0, CommissionDispute::count());
    }

    public function test_admin_decision_is_logged_notifies_partner_restores_line_and_cannot_be_repeated(): void
    {
        Queue::fake();
        $a = $this->completed('10:00');
        $this->qualify();
        $this->viewAfter();
        $svc = app(DisputeService::class);
        $d = $svc->open($this->spa->partner, $a, $this->partnerUser(), 'service_not_performed', 'Client absent', null, CarbonImmutable::parse($this->day)->addMonthNoOverflow()->startOfMonth()->addDays(1));
        $this->assertSame(0.0, $this->statement()->total);

        try {
            $svc->decide($d, $this->admin, 'rejected', '');
            $this->fail('motivation obligatoire');
        } catch (DisputeException $e) {
            $this->assertSame('note_required', $e->reason);
        }
        $this->actingAs($this->admin);
        $d = $svc->decide($d, $this->admin, 'rejected', 'Prestation confirmée par le client.');
        $this->assertSame('rejected', $d->status);
        $this->assertNull($d->open_booking_id);
        $this->assertSame($this->admin->id, $d->decided_by);
        $this->assertSame(60.0, $this->statement()->total, 'La ligne revient dans le relevé');
        $this->assertSame(1, LedgerEntry::where('booking_id', $a->id)->count(), 'Aucune écriture ajoutée par la décision');

        $log = ActivityLog::where('action', 'billing.dispute_decided')->first();
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame('rejected', $log->properties['decision']);
        $n = BillingNotification::where('event', 'dispute_decided')->firstOrFail();
        $this->assertSame($this->partnerUser()->email, $n->recipient);
        $this->assertSame('partner', $n->audience);

        try {
            $svc->decide($d, $this->admin, 'accepted', 'Encore');
            $this->fail('double décision');
        } catch (DisputeException $e) {
            $this->assertSame('not_open', $e->reason);
        }
        // Une nouvelle contestation redevient possible après décision (verrou libéré), une seule à la fois.
        $svc->open($this->spa->partner, $a, $this->partnerUser(), 'other', 'Nouvel élément', null, CarbonImmutable::parse($this->day)->addMonthNoOverflow()->startOfMonth()->addDays(1));
        $this->assertSame(2, CommissionDispute::count());
    }

    public function test_statement_command_notifies_each_partner_once_and_nothing_before_start(): void
    {
        Queue::fake();
        $this->completed('10:00');
        $this->qualify();
        $this->viewAfter();
        $this->artisan('hl:billing-statements', ['--period' => $this->period])->assertSuccessful();
        $this->artisan('hl:billing-statements', ['--period' => $this->period])->assertSuccessful();
        $n = BillingNotification::where('event', 'statement_available')->get();
        $this->assertCount(1, $n);
        $this->assertSame($this->partnerUser()->email, $n[0]->recipient);
        $this->assertSame($this->period, $n[0]->payload['period']);
        $this->assertSame(60.0, (float) $n[0]->payload['total']);
        $this->assertSame(1, ActivityLog::where('action', 'billing.statement_notified')->count());

        $before = CarbonImmutable::now()->subMonths(6)->format('Y-m');
        $this->artisan('hl:billing-statements', ['--period' => $before])->assertSuccessful();
        $this->assertSame(1, BillingNotification::count());
    }

    public function test_notification_job_records_real_delivery_and_failure_then_retries_without_duplicate(): void
    {
        Mail::fake();
        $a = $this->completed('10:00');
        $other = $this->completed('12:00');
        $this->qualify();
        $this->viewAfter();
        $d = app(DisputeService::class)->open($this->spa->partner, $a, $this->partnerUser(), 'other', 'x', null, CarbonImmutable::parse($this->day)->addMonthNoOverflow()->startOfMonth()->addDays(1));
        $n = BillingNotification::where('event', 'dispute_opened')->firstOrFail();
        $this->assertSame('sent', $n->fresh()->status, 'Envoi synchrone en test (queue sync)');
        Mail::assertSent(BillingMail::class, fn (BillingMail $m) => $m->notification->id === $n->id && $m->hasTo(config('hl.alert_email')));

        $real = app(MailManager::class);
        $broken = Mockery::mock(MailManager::class)->shouldIgnoreMissing();
        $broken->shouldReceive('to')->andThrow(new RuntimeException('SMTP 550'));
        Mail::swap($broken);
        try {
            // File synchrone en test : l'échec SMTP remonte jusqu'à l'appelant ; la contestation est néanmoins enregistrée.
            app(DisputeService::class)->open($this->spa->partner, $other, $this->partnerUser(), 'other', 'y', null, CarbonImmutable::parse($this->day)->addMonthNoOverflow()->startOfMonth()->addDays(1));
        } catch (RuntimeException) {
        }
        $this->assertSame(2, CommissionDispute::count());
        $failed = BillingNotification::where('notifiable_id', '!=', $d->id)->where('event', 'dispute_opened')->firstOrFail();
        $this->assertSame('failed', $failed->status);
        $this->assertStringContainsString('SMTP 550', $failed->error);
        $this->assertGreaterThanOrEqual(1, $failed->attempts);

        Mail::swap($real);
        Mail::fake();
        $this->assertSame(1, app(BillingNotifier::class)->retry());
        $this->assertSame('sent', $failed->fresh()->status);
        $this->assertSame(2, BillingNotification::count());
    }

    public function test_partner_statement_page_is_tenant_bound_and_dispute_action_ignores_foreign_bookings(): void
    {
        $a = $this->completed('10:00');
        $this->qualify();
        $this->viewAfter();
        $owner = $this->partnerUser();
        $this->actingAs($owner)->get('/partenaire/'.$this->spa->slug.'/commission-statement')->assertOk()->assertSee(__('billing.statement_title'));

        $otherUser = User::create(['name' => 'Autre', 'email' => 'autre@example.test', 'password' => 'secret-test', 'role' => 'partner', 'email_verified_at' => now()]);
        $other = Partner::create(['user_id' => $otherUser->id, 'company_name' => 'Autre SARL', 'status' => 'approved']);
        $otherSpa = Spa::create(['partner_id' => $other->id, 'slug' => 'autre-spa', 'name' => 'Autre Spa', 'city' => 'Marrakech', 'status' => 'published', 'published_at' => now(), 'min_lead_minutes' => 0]);
        $this->actingAs($owner)->get('/partenaire/'.$otherSpa->slug.'/commission-statement')->assertNotFound();

        Filament::setCurrentPanel(Filament::getPanel('partner'));
        Filament::setTenant($otherSpa, true);
        Livewire::actingAs($otherUser)->test(CommissionStatement::class)->set('period', $this->period)
            ->assertSee(__('billing.statement_empty'))->assertDontSee($a->reference)
            ->callAction('dispute', ['reason_code' => 'other', 'comment' => 'tentative'], ['booking' => $a->id]);
        $this->assertSame(0, CommissionDispute::count(), 'Réservation d’un autre partenaire ignorée');

        Filament::setTenant($this->spa, true);
        Livewire::actingAs($owner)->test(CommissionStatement::class)->set('period', $this->period)->assertSee($a->reference)
            ->callAction('dispute', ['reason_code' => 'amount_incorrect', 'comment' => 'Montant erroné'], ['booking' => $a->id])
            ->assertHasNoActionErrors();
        $this->assertSame(1, CommissionDispute::where('partner_id', $this->spa->partner_id)->where('booking_id', $a->id)->count());
        $this->assertSame(0.0, $this->statement()->total);
        $this->actingAs($owner)->get('/partenaire/'.$this->spa->slug.'/commission-disputes')->assertOk()->assertSee($a->reference);
        Filament::setTenant($otherSpa, true);
        $this->assertSame(0, CommissionDisputeResource::getEloquentQuery()->count(), 'Ressource partenaire filtrée par entité du tenant');
        Filament::setTenant($this->spa, true);
        $this->assertSame(1, CommissionDisputeResource::getEloquentQuery()->count());
    }

    public function test_dispute_window_falls_back_to_config_when_setting_saved_empty(): void
    {
        BillingSetting::updateOrCreate(['key' => 'dispute_until_day'], ['value' => null]);
        $this->assertSame(4, app(BillingSettings::class)->get('dispute_until_day'));
        $this->assertSame(CarbonImmutable::parse($this->period.'-01')->addMonth()->day(4)->format('Y-m-d'), $this->statement()->disputeUntil->format('Y-m-d'));
    }

    public function test_admin_dispute_list_is_reserved_to_admins(): void
    {
        $a = $this->completed('10:00');
        $this->qualify();
        $this->viewAfter();
        app(DisputeService::class)->open($this->spa->partner, $a, $this->partnerUser(), 'other', 'À vérifier');
        $this->travelBack();
        $this->actingAs($this->admin)->get('/admin/commission-disputes')->assertOk()->assertSee($a->reference);
        $this->actingAs($this->partnerUser())->get('/admin/commission-disputes')->assertForbidden();
    }
}
