<?php

namespace Tests\Engine;

use App\Domain\Booking\BookingException;
use App\Domain\Booking\CancellationService;
use App\Domain\Messaging\MessageService;
use App\Filament\Admin\Resources\BookingResource\Pages\ViewBooking as AdminViewBooking;
use App\Filament\Admin\Resources\CancellationRequestResource\Pages\ListCancellationRequests;
use App\Filament\Partner\Resources\BookingResource as PartnerBookingResource;
use App\Filament\Partner\Resources\BookingResource\Pages\ViewBooking;
use App\Livewire\Site\BookingShow;
use App\Mail\BookingMessageMail;
use App\Mail\CancellationRequestMail;
use App\Models\Booking;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/** Étapes B et C — demande d'annulation partenaire (client informé, décision admin) et messagerie liée à la réservation. */
class CancellationAndMessagingTest extends BookingFlowTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('fr');
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'secret-test', 'role' => 'admin', 'email_verified_at' => now()]);
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

    // ---------------------------------------------------------------- B

    public function test_partner_cannot_cancel_directly_and_request_needs_a_reason(): void
    {
        $b = $this->confirmed();
        $this->asPartner();

        $page = Livewire::test(ViewBooking::class, ['record' => $b->getRouteKey()])
            ->assertActionHidden('cancel')->assertActionVisible('requestCancellation');
        $page->callAction('requestCancellation', ['reason_code' => 'staff_unavailable', 'reason' => 'court'])->assertHasActionErrors(['reason']);
        $this->assertSame(0, $b->cancellationRequests()->count());

        $page->callAction('requestCancellation', ['reason_code' => 'staff_unavailable', 'reason' => 'Thérapeute malade, aucun remplacement possible ce jour.'])->assertHasNoActionErrors();
        $r = $b->cancellationRequests()->first();
        $this->assertSame('pending', $r->status);
        $this->assertSame($this->spa->partner->user_id, $r->requested_by);
        $this->assertNotNull($r->client_notified_at);
        $this->assertSame('client_notified', $r->step());

        // La réservation reste confirmée et le créneau reste occupé.
        $this->assertSame('confirmed', $b->fresh()->status);
        $this->assertNotContains('10:00', $this->availability(['treatment' => $this->m->id, 'party' => 2])['times'], 'Capacité toujours allouée');

        // Client + admin prévenus ; le partenaire voit l'état sans pouvoir redemander.
        Mail::assertQueued(CancellationRequestMail::class, fn ($m) => $m->hasTo('client@example.test') && $m->audience === 'client');
        Mail::assertQueued(CancellationRequestMail::class, fn ($m) => $m->hasTo('admin@example.test') && $m->audience === 'admin');
        $page->assertSee('Client informé')->assertActionHidden('requestCancellation');
        $this->assertFalse($b->fresh()->canRequestCancellation());
    }

    public function test_client_answers_from_secure_link_then_admin_refuses_and_booking_stays(): void
    {
        $b = $this->confirmed();
        $svc = app(CancellationService::class);
        $r = $svc->request($b, $this->spa->partner->user, 'Fermeture exceptionnelle pour travaux urgents.');

        Livewire::test(BookingShow::class, ['token' => $b->manage_token])
            ->assertSee('Demande d’annulation de l’établissement')->assertSee('travaux urgents')->assertSee('reste confirmée')
            ->call('respondCancellation', 'refused')
            ->assertSee('votre réponse a été transmise')->assertSee('conserver votre réservation')->assertDontSee('J’accepte l’annulation');

        $r->refresh();
        $this->assertSame('refused', $r->client_response);
        $this->assertSame('client_refused', $r->step());
        Mail::assertQueued(CancellationRequestMail::class, fn ($m) => $m->audience === 'partner' && $m->event === 'client_response');

        // Réponse unique.
        $this->expectException(BookingException::class);
        try {
            $svc->clientRespond($r, 'accepted');
        } finally {
            $svc->decide($r, $this->admin, 'refused', 'Nous avons trouvé une solution avec l’établissement.');
            $r->refresh();
            $this->assertSame('refused', $r->status);
            $this->assertSame($this->admin->id, $r->decided_by);
            $this->assertSame('confirmed', $b->fresh()->status, 'Refus admin : la réservation est maintenue');
            $this->assertNotContains('10:00', $this->availability(['treatment' => $this->m->id, 'party' => 2])['times']);
            Mail::assertQueued(CancellationRequestMail::class, fn ($m) => $m->hasTo('client@example.test') && $m->event === 'refused');
        }
    }

    public function test_only_admin_acceptance_cancels_and_releases_capacity_idempotently(): void
    {
        $b = $this->confirmed();
        $svc = app(CancellationService::class);
        $r = $svc->request($b, $this->spa->partner->user, 'Double réservation par erreur de saisie.');
        $svc->clientRespond($r, 'accepted');
        $this->assertSame('confirmed', $b->fresh()->status, 'L’accord du client n’annule pas seul');

        app(MessageService::class)->send($b, 'client', 'Merci de confirmer l’annulation.');
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(AdminViewBooking::class, ['record' => $b->getRouteKey()])
            ->assertSee('Double réservation')->assertSee('Merci de confirmer l’annulation')->assertSee('Net partenaire');
        Livewire::test(ListCancellationRequests::class)
            ->assertCanSeeTableRecords([$r])->assertSee($b->reference)->assertSee('Client d’accord')
            ->callTableAction('acceptRequest', $r, ['note' => 'OK, client d’accord.'])->assertHasNoTableActionErrors();

        $r->refresh();
        $this->assertSame('accepted', $r->status);
        $this->assertSame('cancelled', $b->fresh()->status);
        $this->assertSame(0, $b->allocations()->where('status', 'active')->count());
        $this->assertContains('10:00', $this->availability(['treatment' => $this->m->id, 'party' => 2])['times'], 'Cabine libérée uniquement après la décision admin');
        $this->assertSame('admin', $b->fresh()->cancelled_by);

        $this->expectException(BookingException::class);
        $svc->decide($r, $this->admin, 'accepted');
    }

    public function test_request_is_closed_when_client_cancels_meanwhile_and_rates_are_computed(): void
    {
        $b = $this->confirmed();
        $svc = app(CancellationService::class);
        $r = $svc->request($b, $this->spa->partner->user, 'Le client nous a prévenus par téléphone.');
        $this->bookings->cancel($b, 'client');
        $this->assertSame('closed', $r->fresh()->status);

        $b2 = $this->confirmed(['treatment' => $this->h->id, 'party' => 2]);
        $rate = CancellationService::rateFor($this->spa->id);
        $this->assertSame(1, $rate['requests']);
        $this->assertSame(2, $rate['bookings']);
        $this->assertSame(50.0, $rate['rate']);
        $this->assertTrue($b2->canRequestCancellation());
        $this->assertFalse($this->submit($this->intent('17:00', ['treatment' => $this->m->id, 'party' => 1]))['booking']->canRequestCancellation(), 'En attente : pas de demande possible');
    }

    // ---------------------------------------------------------------- C

    public function test_guest_and_partner_exchange_messages_with_read_state_and_single_notification(): void
    {
        $b = $this->confirmed();
        $msgs = app(MessageService::class);

        Livewire::test(BookingShow::class, ['token' => $b->manage_token])
            ->assertSee('Messagerie avec l’établissement')->assertSee('Aucun message')
            ->set('messageBody', 'Bonjour, nous arriverons vers 9h45. Joignable au 06 12 34 56 78.')
            ->call('sendMessage')->assertSee('Message envoyé')->assertSee('arriverons vers 9h45')->assertSee('06 12 34 56 78');
        $m1 = $b->messages()->first();
        $this->assertSame('client', $m1->sender);
        $this->assertNull($m1->read_at);
        $this->assertNotNull($m1->notified_at);
        Mail::assertQueued(BookingMessageMail::class, fn ($m) => $m->hasTo($this->spa->partner->user->email) && $m->audience === 'partner');

        // Deuxième message avant lecture : pas de second e-mail.
        $msgs->send($b, 'client', 'Et une personne est allergique au miel.');
        Mail::assertQueued(BookingMessageMail::class, 1);
        $this->assertSame(2, $b->unreadMessagesFor('partner'));

        $this->asPartner();
        $this->assertSame(2, PartnerBookingResource::unreadMessages());
        $this->assertSame('2', PartnerBookingResource::getNavigationBadge());
        $page = Livewire::test(ViewBooking::class, ['record' => $b->getRouteKey()])
            ->assertSee('allergique au miel')->assertDontSee('client@example.test');
        $this->assertSame(0, $b->unreadMessagesFor('partner'), 'Ouvrir la fiche marque les messages comme lus');
        $this->assertSame(0, PartnerBookingResource::unreadMessages());

        $page->set('messageBody', 'Bien noté, à demain !')->call('sendMessage')->assertSee('Bien noté, à demain');
        $m3 = $b->messages()->latest('id')->first();
        $this->assertSame('partner', $m3->sender);
        $this->assertSame($this->spa->partner->user_id, $m3->user_id);
        Mail::assertQueued(BookingMessageMail::class, fn ($m) => $m->hasTo('client@example.test') && $m->audience === 'client');
        $this->assertSame(1, $b->unreadMessagesFor('client'));

        Livewire::test(BookingShow::class, ['token' => $b->manage_token])->assertSee('Bien noté, à demain')->assertSee('HL TEST Flow');
        $this->assertSame(0, $b->unreadMessagesFor('client'));

        $this->expectException(BookingException::class);
        $msgs->send($b, 'client', '');
    }

    public function test_messages_are_masked_before_confirmation_closed_after_cancellation_and_tenant_isolated(): void
    {
        $waiting = $this->submit($this->intent('17:00', ['treatment' => $this->m->id, 'party' => 1]))['booking'];
        Mail::fake();
        $m = app(MessageService::class)->send($waiting, 'client', 'Appelez-moi au 06 12 34 56 78 ou test@mail.com');
        $this->assertStringNotContainsString('0612', str_replace(' ', '', $m->body));
        $this->assertStringNotContainsString('test@mail.com', $m->body);

        $b = $this->confirmed();
        $this->bookings->cancel($b, 'client');
        try {
            app(MessageService::class)->send($b, 'partner', 'Trop tard.', $this->spa->partner->user);
            $this->fail('Messagerie fermée après annulation');
        } catch (BookingException $e) {
            $this->assertSame('status', $e->reason);
        }

        // Un autre partenaire ne voit ni la réservation ni ses messages.
        $spaA = $this->spa;
        $this->spa('hl-other', 'HL Other');
        $this->actingAs($this->spa->partner->user);
        Filament::setCurrentPanel(Filament::getPanel('partner'));
        Filament::setTenant($this->spa, true);
        $this->assertSame(0, PartnerBookingResource::unreadMessages());
        $this->spa = $spaA;
        $this->expectException(ModelNotFoundException::class);
        Livewire::test(ViewBooking::class, ['record' => $waiting->getRouteKey()]);
    }

    public function test_public_page_never_exposes_internal_data(): void
    {
        $b = $this->confirmed(['treatment' => $this->hm->id, 'party' => 2, 'extras' => [$this->cr->id]]);
        $b->notes()->create(['spa_id' => $this->spa->id, 'user_id' => $this->spa->partner->user_id, 'body' => 'Note interne secrète']);
        app(MessageService::class)->send($b, 'partner', 'Message visible côté client.', $this->spa->partner->user);

        $this->get(route('booking.show', ['locale' => 'fr', 'token' => $b->manage_token]))->assertOk()
            ->assertSee('Message visible côté client')
            ->assertDontSee('Note interne secrète')->assertDontSee('commissionnable')->assertDontSee('Net partenaire')->assertDontSee('Commission');
        $this->get(route('booking.show', ['locale' => 'fr', 'token' => 'not-a-token']))->assertNotFound();
    }
}
