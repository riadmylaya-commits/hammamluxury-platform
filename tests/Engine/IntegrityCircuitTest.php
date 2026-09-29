<?php

namespace Tests\Engine;

use App\Domain\Booking\BookingException;
use App\Domain\Booking\CancellationService;
use App\Domain\Booking\IncidentService;
use App\Filament\Admin\Resources\BookingResource\Pages\ViewBooking as AdminViewBooking;
use App\Filament\Admin\Resources\CancellationRequestResource\Pages\ListCancellationRequests;
use App\Filament\Admin\Resources\ClientIncidentResource\Pages\ListClientIncidents;
use App\Filament\Partner\Resources\BookingResource\Pages\ViewBooking;
use App\Livewire\Site\BookingShow;
use App\Mail\CancellationRequestMail;
use App\Mail\GuestReportMail;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\ClientIncident;
use App\Models\LedgerEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/**
 * Session A — circuit d'intégrité : no-show client (fenêtre start_at → end_at + 4 h, frais appliqués / abandonnés, commission),
 * no-show partenaire, signalement client, demande d'annulation structurée + anti-répétition, proposition de date, coordonnées masquées.
 */
class IntegrityCircuitTest extends BookingFlowTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('fr');
        URL::defaults(['locale' => 'fr']);
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'secret-test', 'role' => 'admin', 'email_verified_at' => now()]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function confirmed(array $sel = []): Booking
    {
        $b = $this->submit($this->intent('10:00', $sel ?: ['treatment' => $this->m->id, 'party' => 1]))['booking'];
        Mail::fake();
        $this->bookings->accept($b, 'partner');

        return $b->fresh();
    }

    private function asPartner(): void
    {
        $this->actingAs($this->spa->partner->user);
        Filament::setCurrentPanel(Filament::getPanel('partner'));
        Filament::setTenant($this->spa, true);
    }

    private function asAdmin(): void
    {
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant(null, true);
    }

    private function freezeAt(CarbonImmutable $at): void
    {
        CarbonImmutable::setTestNow($at);
    }

    // ------------------------------------------------------------ no-show client

    public function test_partner_no_show_window_opens_at_start_and_closes_four_hours_after_end(): void
    {
        $b = $this->confirmed(); // massage 60 min → 10:00-11:00 ; fenêtre jusqu'à 15:00
        $this->assertSame(240, (int) config('hl.auto_complete_after_min'));

        $this->freezeAt($b->start_at->subMinute());
        $this->assertFalse($b->partnerNoShowWindowOpen());
        try {
            $this->bookings->noShow($b, 'partner');
            $this->fail('Avant le rendez-vous : refusé');
        } catch (BookingException $e) {
            $this->assertSame(__('booking.no_show_not_allowed'), $e->getMessage());
        }

        $this->freezeAt($b->start_at);
        $this->assertTrue($b->partnerNoShowWindowOpen(), 'Ouvert dès start_at');
        $this->freezeAt($b->end_at->addHours(4));
        $this->assertTrue($b->partnerNoShowWindowOpen(), 'Encore ouvert à end_at + 4 h exactement');
        $this->assertSame([], $this->bookings->completePast(), 'L’auto-completion n’a pas fermé la fenêtre');

        $this->freezeAt($b->end_at->addHours(4)->addMinute());
        $this->assertFalse($b->partnerNoShowWindowOpen());
        try {
            $this->bookings->noShow($b, 'partner');
            $this->fail('Après end_at + 4 h : refusé au partenaire');
        } catch (BookingException $e) {
            $this->assertSame(__('booking.no_show_window_closed'), $e->getMessage());
        }
        $this->assertSame('confirmed', $b->fresh()->status);

        // Le cron termine ensuite la réservation, avec commission ; l'Admin peut encore corriger en no-show.
        $this->assertSame([$b->id], $this->bookings->completePast());
        $this->assertSame('completed', $b->fresh()->status);
        $this->assertSame(1, LedgerEntry::where('booking_id', $b->id)->count());
        $this->bookings->noShow($b->fresh(), 'admin', false, 'Correction tardive à la demande de l’établissement.');
        $b->refresh();
        $this->assertSame('no_show', $b->status);
        $this->assertTrue($b->no_show_fee_waived);
        $this->assertSame(0, LedgerEntry::where('booking_id', $b->id)->count(), 'Frais abandonnés : commission retirée');
        $this->assertSame('admin', $b->events()->where('type', 'no_show:fee_waived')->value('actor'));
    }

    public function test_no_show_with_fee_keeps_commission_and_waived_fee_records_no_commission(): void
    {
        $paid = $this->confirmed();
        $free = $this->confirmed();
        $this->freezeAt($paid->start_at->addMinutes(20));

        $this->asPartner();
        $page = Livewire::test(ViewBooking::class, ['record' => $paid->getRouteKey()])
            ->assertActionVisible('no_show')->assertActionHidden('partner_no_show')->assertActionVisible('reportGuest');
        $page->callAction('no_show', ['fee' => 'apply', 'note' => 'Aucune nouvelle du client.'])->assertHasNoActionErrors();

        $paid->refresh();
        $this->assertSame('no_show', $paid->status);
        $this->assertSame(400.0, $paid->no_show_fee);
        $this->assertFalse($paid->no_show_fee_waived);
        $this->assertNotNull($paid->no_show_at);
        $this->assertSame(60.0, (float) LedgerEntry::where('booking_id', $paid->id)->where('type', 'commission')->value('amount'), 'Commission 15 % conservée');
        $this->assertSame(0, $paid->allocations()->where('status', 'active')->count(), 'Créneau libéré');
        $this->assertSame(1, $paid->events()->where('type', 'no_show:fee_applied')->count());
        $this->assertSame(1, ActivityLog::where('action', 'booking.no_show_fee_applied')->count());

        $this->bookings->noShow($free->fresh(), 'partner', false, 'Client hospitalisé, geste commercial.');
        $free->refresh();
        $this->assertSame('no_show', $free->status);
        $this->assertSame(0.0, $free->no_show_fee);
        $this->assertTrue($free->no_show_fee_waived);
        $this->assertSame(0, LedgerEntry::where('booking_id', $free->id)->count(), 'Frais abandonnés : aucune commission');
        $this->assertSame(1, ActivityLog::where('action', 'booking.no_show_fee_waived')->count());

        // Second no-show interdit (déjà no_show) ; l'écriture reste unique.
        try {
            $this->bookings->noShow($paid->fresh(), 'admin');
            $this->fail();
        } catch (BookingException $e) {
            $this->assertSame(__('booking.no_show_not_allowed'), $e->getMessage());
        }
        $this->assertSame(1, LedgerEntry::where('booking_id', $paid->id)->count());
    }

    public function test_partner_no_show_is_admin_only_and_recorded_as_serious_incident(): void
    {
        $b = $this->confirmed();
        $svc = app(CancellationService::class);
        $r = $svc->request($b, $this->spa->partner->user, 'Fermeture administrative imposée par la préfecture.', 'administrative_closure');
        $svc->decide($r, $this->admin, 'refused', 'Aucun justificatif.');

        try {
            $this->bookings->partnerNoShow($b, 'partner');
            $this->fail();
        } catch (BookingException $e) {
            $this->assertSame(__('booking.admin_only'), $e->getMessage());
        }
        try {
            $this->bookings->partnerNoShow($b, 'admin');
            $this->fail('Impossible avant le rendez-vous');
        } catch (BookingException $e) {
            $this->assertSame(__('booking.no_show_not_allowed'), $e->getMessage());
        }

        $this->freezeAt($b->end_at->addHours(5));
        $this->assertSame([$b->id], $this->bookings->completePast());
        $this->asAdmin();
        Livewire::test(AdminViewBooking::class, ['record' => $b->getRouteKey()])
            ->assertActionVisible('partner_no_show')->assertActionVisible('no_show')
            ->callAction('partner_no_show', ['note' => 'Client arrivé, porte fermée. Réservation non honorée.'])->assertHasNoActionErrors();

        $b->refresh();
        $this->assertSame('partner_no_show', $b->status);
        $this->assertSame(0, LedgerEntry::where('booking_id', $b->id)->count(), 'Aucune commission sur une réservation non honorée');
        $this->assertSame(1, ActivityLog::where('action', 'booking.partner_no_show')->count());
        $this->assertSame(1, $b->events()->where('type', 'partner_no_show')->count());

        Livewire::test(BookingShow::class, ['token' => $b->manage_token])
            ->assertSee('Non honorée par l’établissement')->assertDontSee('Confirmée');
    }

    // ------------------------------------------------------------ signalement client

    public function test_partner_reports_guest_after_appointment_and_admin_reviews_cross_venue_history(): void
    {
        $b = $this->confirmed();
        $svc = app(IncidentService::class);
        $partner = $this->spa->partner->user;

        try {
            $svc->reportGuest($b, $partner, 'aggression', 'Le client a menacé la thérapeute et refusé de partir.');
            $this->fail('Interdit avant le rendez-vous');
        } catch (BookingException $e) {
            $this->assertSame(__('booking.guest_report_not_allowed'), $e->getMessage());
        }

        $this->freezeAt($b->start_at->addMinutes(10));
        try {
            $svc->reportGuest($b, $partner, 'rudeness', 'Le client a menacé la thérapeute et refusé de partir.');
            $this->fail();
        } catch (BookingException $e) {
            $this->assertSame(__('booking.invalid'), $e->getMessage());
        }
        try {
            $svc->reportGuest($b, $partner, 'aggression', 'Trop court');
            $this->fail();
        } catch (BookingException $e) {
            $this->assertSame(__('booking.guest_report_description_required'), $e->getMessage());
        }

        $this->asPartner();
        Livewire::test(ViewBooking::class, ['record' => $b->getRouteKey()])
            ->callAction('reportGuest', ['category' => 'aggression', 'description' => 'Le client a menacé la thérapeute et refusé de partir.'])
            ->assertHasNoActionErrors()->assertSee('Comportement du client signalé');

        $i = ClientIncident::first();
        $this->assertSame('open', $i->status);
        $this->assertSame($b->id, $i->booking_id);
        $this->assertSame($partner->id, $i->reported_by);
        $this->assertSame($b->clientKey(), $i->client_key);
        $this->assertSame(0, $i->otherSpasCount());
        $this->assertSame('confirmed', $b->fresh()->status, 'Un signalement ne change pas le statut');
        Mail::assertQueued(GuestReportMail::class);
        $this->assertSame(1, ActivityLog::where('action', 'booking.guest_reported')->count());

        // Même client (même téléphone) dans un autre établissement → récidive visible par l'Admin.
        $main = $this->spa;
        $otherSpa = $this->spa('hl-other', 'HL Other');
        $this->spa = $main;
        ClientIncident::create(['booking_id' => $b->id, 'spa_id' => $otherSpa->id, 'client_key' => $b->clientKey(), 'category' => 'damage', 'description' => str_repeat('x', 30), 'status' => 'open']);
        $this->assertSame(1, $i->fresh()->otherSpasCount());

        $this->asAdmin();
        Livewire::test(ListClientIncidents::class)
            ->assertCanSeeTableRecords([$i])->assertSee($b->reference)->assertSee('Agressivité')
            ->callTableAction('reviewIncident', $i, ['note' => 'Confirmé par les messages échangés.'])->assertHasNoTableActionErrors();
        $i->refresh();
        $this->assertSame('reviewed', $i->status);
        $this->assertSame($this->admin->id, $i->reviewed_by);
        $this->assertSame('Confirmé par les messages échangés.', $i->admin_note);
        $this->assertSame(1, ActivityLog::where('action', 'booking.guest_report_reviewed')->count());
    }

    // ------------------------------------------------------------ demande d'annulation structurée

    public function test_cancellation_request_needs_reason_code_and_same_refused_reason_is_blocked(): void
    {
        $b = $this->confirmed();
        $svc = app(CancellationService::class);
        $partner = $this->spa->partner->user;

        try {
            $svc->request($b, $partner, 'Explication suffisamment longue.', 'holiday');
            $this->fail();
        } catch (BookingException $e) {
            $this->assertSame(__('booking.cancellation_reason_code_required'), $e->getMessage());
        }

        $r = $svc->request($b, $partner, 'Chaudière en panne, aucun hammam possible ce jour.', 'technical_failure', 'evidence/test.pdf');
        $this->assertSame('technical_failure', $r->reason_code);
        $this->assertSame('evidence/test.pdf', $r->evidence_path);
        $this->assertSame('confirmed', $b->fresh()->status);
        $this->assertNotContains('10:00', $this->availability(['treatment' => $this->m->id, 'party' => 2])['times'], 'Créneau toujours bloqué');

        $svc->decide($r, $this->admin, 'refused', 'Cabines de massage disponibles.');
        $this->assertSame('confirmed', $b->fresh()->status);

        try {
            $svc->request($b, $partner, 'Chaudière toujours en panne, vraiment.', 'technical_failure');
            $this->fail('Même motif déjà refusé');
        } catch (BookingException $e) {
            $this->assertSame(__('booking.cancellation_same_reason_refused'), $e->getMessage());
        }
        $this->assertSame(1, $b->cancellationRequests()->count());

        // Nouvel événement / nouveau motif : autorisé.
        $r2 = $svc->request($b, $partner, 'Thérapeute hospitalisée ce matin, aucun remplaçant.', 'staff_unavailable');
        $this->assertSame('pending', $r2->status);

        $this->asPartner();
        Livewire::test(ViewBooking::class, ['record' => $b->getRouteKey()])
            ->assertSee('Panne ou problème technique')->assertSee('Indisponibilité exceptionnelle');
    }

    public function test_admin_proposes_new_date_and_booking_moves_only_when_client_accepts(): void
    {
        $b = $this->confirmed();
        $svc = app(CancellationService::class);
        $r = $svc->request($b, $this->spa->partner->user, 'Fermeture administrative imposée ce jour.', 'administrative_closure');
        $newStart = $this->at('16:00');
        $oldStart = $b->start_at;

        $this->asAdmin();
        Livewire::test(ListCancellationRequests::class)
            ->assertSee('Fermeture administrative')
            ->callTableAction('proposeDate', $r, ['start_at' => $newStart->toDateTimeString(), 'note' => 'Même massage l’après-midi, thé offert.'])
            ->assertHasNoTableActionErrors();
        $r->refresh();
        $this->assertTrue($r->hasOpenProposal());
        $this->assertSame('proposed', $r->step());
        $this->assertSame('confirmed', $b->fresh()->status);
        $this->assertEquals($oldStart, $b->fresh()->start_at, 'Rien ne bouge avant l’accord du client');
        Mail::assertQueued(CancellationRequestMail::class, fn ($m) => $m->hasTo('client@example.test') && $m->event === 'proposed');

        try {
            $svc->propose($r, $this->admin, $this->at('17:00'));
            $this->fail('Une seule proposition ouverte');
        } catch (BookingException $e) {
            $this->assertSame(__('booking.cancellation_proposal_pending'), $e->getMessage());
        }

        // Refus client : la réservation reste confirmée à sa date, la demande revient à l'Admin.
        Livewire::test(BookingShow::class, ['token' => $b->manage_token])
            ->assertSee('Nouvelle date proposée')->assertSee('thé offert')->assertSee('reste confirmée')
            ->call('respondProposal', 'refused')
            ->assertSee('refusé la nouvelle date')->assertDontSee('J’accepte la nouvelle date');
        $r->refresh();
        $this->assertSame('refused', $r->proposal_response);
        $this->assertSame('proposal_refused', $r->step());
        $this->assertTrue($r->isPending());
        $this->assertEquals($oldStart, $b->fresh()->start_at);

        // Deuxième proposition acceptée : réservation déplacée sous verrou, ancien créneau libéré, demande close.
        $svc->propose($r, $this->admin, $newStart);
        Livewire::test(BookingShow::class, ['token' => $b->manage_token])
            ->call('respondProposal', 'accepted')->assertSee('a été déplacée');
        $r->refresh();
        $b->refresh();
        $this->assertSame('rescheduled', $r->status);
        $this->assertSame('confirmed', $b->status);
        $this->assertEquals($newStart, $b->start_at);
        $this->assertEquals($newStart->addMinutes(60), $b->end_at);
        $this->assertContains('10:00', $this->availability(['treatment' => $this->m->id, 'party' => 2])['times'], 'Ancien créneau libéré');
        $this->assertNotContains('16:00', $this->availability(['treatment' => $this->m->id, 'party' => 2])['times'], 'Nouveau créneau occupé');
        $this->assertSame(1, $b->allocations()->where('status', 'active')->count());
        $this->assertSame(1, $b->events()->where('type', 'rescheduled')->count());
        $this->assertSame(1, $b->events()->where('type', 'cancellation:rescheduled')->count());
        Mail::assertQueued(CancellationRequestMail::class, fn ($m) => $m->hasTo('client@example.test') && $m->event === 'rescheduled');

        $this->expectException(BookingException::class);
        $svc->decide($r, $this->admin, 'accepted');
    }

    // ------------------------------------------------------------ coordonnées

    public function test_contact_hidden_before_confirmation_then_phone_and_whatsapp_revealed_but_never_email(): void
    {
        $b = $this->submit($this->intent('10:00', ['treatment' => $this->m->id, 'party' => 1]))['booking'];
        $this->assertSame('waiting', $b->status);
        $this->assertFalse($b->contactVisibleToPartner());
        $this->assertNull($b->contact_revealed_at);

        $this->asPartner();
        Livewire::test(ViewBooking::class, ['record' => $b->getRouteKey()])
            ->assertSee('Coordonnées visibles après confirmation')
            ->assertDontSee('client@example.test')->assertDontSee('wa.me')->assertDontSee('tel:+212');

        Mail::fake();
        $this->bookings->accept($b, 'partner');
        $b->refresh();
        $this->assertTrue($b->contactVisibleToPartner());
        $this->assertNotNull($b->contact_revealed_at);
        $this->assertSame(1, $b->events()->where('type', 'contact:revealed')->count());

        Livewire::test(ViewBooking::class, ['record' => $b->getRouteKey()])
            ->assertDontSee('Coordonnées visibles après confirmation')
            ->assertSee('wa.me')->assertSee('tel:+212')
            ->assertDontSee('client@example.test')
            ->assertDontSee('Supprimer la note');

        $this->asAdmin();
        Livewire::test(AdminViewBooking::class, ['record' => $b->getRouteKey()])->assertSee('client@example.test');
    }
}
