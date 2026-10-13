<?php

namespace Tests\Engine;

use App\Domain\Booking\BookingException;
use App\Domain\Booking\CancellationService;
use App\Filament\Admin\Resources\CancellationRequestResource\Pages\ListCancellationRequests;
use App\Mail\CancellationRequestMail;
use App\Models\CancellationRequest;
use App\Models\LedgerEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/** Lot A — demande d'annulation partenaire encore ouverte au rendez-vous : ni clôture ni commission automatiques, arbitrage HammamLuxury. */
class CancellationArbitrationTest extends BookingFlowTestCase
{
    private User $admin;

    private CancellationService $cancellations;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('fr');
        $this->cancellations = app(CancellationService::class);
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'secret-test', 'role' => 'admin', 'email_verified_at' => now()]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** Réservation confirmée avec une demande d'annulation partenaire ouverte, horloge placée après le rendez-vous. */
    private function openRequestPastAppointment(): array
    {
        CarbonImmutable::setTestNow();
        $b = $this->submit($this->intent('10:00', ['treatment' => $this->m->id, 'party' => 1]))['booking'];
        Mail::fake();
        $this->bookings->accept($b, 'partner');
        $r = $this->cancellations->request($b->fresh(), $this->spa->partner->user, 'Praticienne absente, impossible d’assurer la prestation', 'staff_unavailable');
        Mail::fake();
        CarbonImmutable::setTestNow($b->end_at->addHours(5));

        return [$b->fresh(), $r->fresh()];
    }

    public function test_auto_completion_is_suspended_and_admin_alerted_once(): void
    {
        [$b, $r] = $this->openRequestPastAppointment();

        $this->assertSame([], $this->bookings->completePast());
        $b->refresh();
        $r->refresh();
        $this->assertSame('confirmed', $b->status, 'pas de clôture automatique tant que la demande est ouverte');
        $this->assertNotNull($b->arbitration_required_at);
        $this->assertNotNull($r->escalated_at);
        $this->assertSame('pending', $r->status);
        $this->assertSame('arbitration', $r->step());
        $this->assertSame(0, LedgerEntry::where('booking_id', $b->id)->count(), 'aucune commission avant décision');
        $this->assertTrue($b->events()->where('type', 'arbitration:required')->exists());
        Mail::assertQueued(CancellationRequestMail::class, fn ($m) => $m->event === 'arbitration' && $m->audience === 'admin' && $m->hasTo('admin@example.test'));

        // Rejeu : rien ne change, aucune nouvelle alerte.
        $this->assertSame([], $this->bookings->completePast());
        $this->assertSame(1, $b->events()->where('type', 'arbitration:required')->count());
        Mail::assertQueuedCount(1);
    }

    public function test_admin_refusal_after_date_completes_and_commissions(): void
    {
        [$b, $r] = $this->openRequestPastAppointment();
        $this->bookings->completePast();

        $this->cancellations->decide($r->fresh(), $this->admin, 'refused', 'Prestation réalisée selon le client');
        $b->refresh();
        $this->assertSame('completed', $b->status);
        $this->assertNull($b->arbitration_required_at);
        $this->assertSame('refused', $r->fresh()->status);
        $this->assertSame(1, LedgerEntry::where('booking_id', $b->id)->where('type', 'commission')->count());
        $this->assertSame((float) $b->commission_amount, (float) LedgerEntry::where('booking_id', $b->id)->sum('amount'));
        $this->assertFalse($b->needsArbitration());

        // La clôture automatique ne touche plus cette réservation.
        $this->assertSame([], $this->bookings->completePast());
    }

    public function test_admin_acceptance_after_date_cancels_without_commission(): void
    {
        [$b, $r] = $this->openRequestPastAppointment();
        $this->bookings->completePast();

        $this->cancellations->decide($r->fresh(), $this->admin, 'accepted', 'Établissement fermé, client non reçu');
        $b->refresh();
        $this->assertSame('cancelled', $b->status);
        $this->assertNull($b->cancel_fee);
        $this->assertSame(0.0, (float) LedgerEntry::where('booking_id', $b->id)->sum('amount'));
        $this->assertSame(0, $b->allocations()->where('status', 'active')->count());
        $this->assertSame('accepted', $r->fresh()->status);
        $this->assertTrue($b->events()->where('type', 'cancel:arbitration')->exists());
    }

    public function test_historical_completed_booking_with_open_request_is_left_untouched_until_manual_decision(): void
    {
        // Cas existant en base : la réservation a déjà été clôturée et commissionnée alors que la demande restait ouverte.
        [$b, $r] = $this->openRequestPastAppointment();
        CancellationRequest::whereKey($r->id)->update(['status' => 'closed']);
        $this->bookings->completePast();
        CancellationRequest::whereKey($r->id)->update(['status' => 'pending']);
        $b->refresh();
        $this->assertSame('completed', $b->status);
        $this->assertSame(1, LedgerEntry::where('booking_id', $b->id)->count());
        $commission = (float) LedgerEntry::where('booking_id', $b->id)->sum('amount');

        // Aucune commande automatique ne modifie ce dossier ; il est listé « à arbitrer ».
        $this->assertSame([], $this->bookings->completePast());
        $this->assertSame([], $this->cancellations->remindAdmins());
        $this->assertTrue($b->fresh()->needsArbitration());
        $this->assertSame('arbitration', $r->fresh()->step());
        $this->assertSame('completed', $b->fresh()->status);

        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ListCancellationRequests::class)->filterTable('arbitration')->assertCanSeeTableRecords([$r]);

        // Décision manuelle « prestation réalisée » : commission maintenue, demande close.
        $this->cancellations->decide($r->fresh(), $this->admin, 'refused', 'Prestation réalisée');
        $this->assertSame('completed', $b->fresh()->status);
        $this->assertSame($commission, (float) LedgerEntry::where('booking_id', $b->id)->sum('amount'));
        $this->assertSame('refused', $r->fresh()->status);
        $this->assertFalse($b->fresh()->needsArbitration());
    }

    public function test_historical_completed_booking_can_be_cancelled_by_admin_with_commission_reversal(): void
    {
        [$b, $r] = $this->openRequestPastAppointment();
        CancellationRequest::whereKey($r->id)->update(['status' => 'closed']);
        $this->bookings->completePast();
        CancellationRequest::whereKey($r->id)->update(['status' => 'pending']);

        $this->cancellations->decide($r->fresh(), $this->admin, 'accepted', 'Client jamais reçu');
        $b->refresh();
        $this->assertSame('cancelled', $b->status);
        $this->assertSame(2, LedgerEntry::where('booking_id', $b->id)->count(), 'commission conservée + contre-écriture traçable');
        $this->assertSame(0.0, (float) LedgerEntry::where('booking_id', $b->id)->sum('amount'));
        $this->assertSame(1, LedgerEntry::where('booking_id', $b->id)->where('type', 'commission_reversal')->count());
    }

    public function test_arbitration_is_refused_when_not_needed(): void
    {
        CarbonImmutable::setTestNow();
        $b = $this->submit($this->intent('10:00', ['treatment' => $this->m->id, 'party' => 1]))['booking'];
        Mail::fake();
        $this->bookings->accept($b, 'partner');
        $this->expectException(BookingException::class);
        $this->bookings->arbitrate($b->fresh(), 'cancelled');
    }

    public function test_reminder_is_sent_once_the_day_before(): void
    {
        CarbonImmutable::setTestNow();
        $b = $this->submit($this->intent('10:00', ['treatment' => $this->m->id, 'party' => 1]))['booking'];
        Mail::fake();
        $this->bookings->accept($b, 'partner');
        $r = $this->cancellations->request($b->fresh(), $this->spa->partner->user, 'Praticienne absente, impossible d’assurer la prestation', 'staff_unavailable');
        Mail::fake();

        $this->assertSame([], $this->cancellations->remindAdmins($b->start_at->subDays(3)), 'trop tôt');
        $this->assertSame([$r->id], $this->cancellations->remindAdmins($b->start_at->subHours(20)));
        $this->assertSame([], $this->cancellations->remindAdmins($b->start_at->subHours(10)), 'un seul rappel');
        Mail::assertQueued(CancellationRequestMail::class, fn ($m) => $m->event === 'reminder' && $m->audience === 'admin');
        Mail::assertQueuedCount(1);
        $this->assertNotNull($r->fresh()->reminder_sent_at);
        $this->artisan('hl:cancellation-reminders')->assertSuccessful();
    }

    public function test_normal_flow_without_request_is_unchanged(): void
    {
        CarbonImmutable::setTestNow();
        $b = $this->submit($this->intent('10:00', ['treatment' => $this->m->id, 'party' => 1]))['booking'];
        Mail::fake();
        $this->bookings->accept($b, 'partner');
        CarbonImmutable::setTestNow($b->end_at->addHours(5));
        $this->assertSame([$b->id], $this->bookings->completePast());
        $this->assertSame('completed', $b->fresh()->status);
        $this->assertNull($b->fresh()->arbitration_required_at);
    }
}
