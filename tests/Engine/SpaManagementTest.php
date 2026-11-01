<?php

namespace Tests\Engine;

use App\Domain\Booking\BookingException;
use App\Domain\Booking\BookingService;
use App\Domain\Partner\OwnerAssignmentService;
use App\Events\BookingCreated;
use App\Events\BookingStatusChanged;
use App\Filament\Admin\Resources\SpaResource\Pages\ListSpas;
use App\Filament\Partner\Resources\BookingResource\Pages\ListBookings;
use App\Filament\Partner\Resources\BookingResource\Pages\ViewBooking;
use App\Mail\PartnerInviteMail;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\BookingNotification;
use App\Models\City;
use App\Models\Spa;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** Gestion de l'établissement : reprise par HammamLuxury, suspension de l'accès, renvoi d'invitation, coordonnées. */
class SpaManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $owner;

    private Spa $spa;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'secret-test', 'role' => 'admin', 'email_verified_at' => now()]);
        $city = City::firstOrCreate(['slug' => 'marrakech'], ['name_fr' => 'Marrakech', 'name_en' => 'Marrakech']);
        $svc = app(OwnerAssignmentService::class);
        $this->actingAs($this->admin);
        $this->spa = $svc->createSpa($this->admin, ['name' => 'Spa Test', 'category' => 'hammam', 'city_id' => $city->id]);
        $svc->assign($this->spa, ['first_name' => 'P', 'last_name' => 'O', 'email' => 'owner@example.test', 'phone' => '+212600000001', 'company_name' => 'Owner SARL'], invite: true);
        $this->spa->refresh();
        $this->owner = User::where('email', 'owner@example.test')->firstOrFail();
    }

    public function test_initial_state_is_partner_and_invite_date_is_recorded(): void
    {
        $svc = app(OwnerAssignmentService::class);
        $this->assertSame('partner', $svc->state($this->spa));
        $this->assertNotNull($this->spa->owner_invited_at);
        $this->assertSame('portfolio', $svc->state($svc->createSpa($this->admin, ['name' => 'Brouillon', 'category' => 'hammam', 'city_id' => City::first()->id])));
    }

    public function test_take_over_routes_requests_to_admin_blocks_partner_decisions_and_hand_back_restores(): void
    {
        $svc = app(OwnerAssignmentService::class);
        $svc->takeOver($this->spa, $this->admin, 'test');
        $this->spa->refresh();
        $this->assertSame('admin', $this->spa->managed_by);
        $this->assertSame($this->admin->id, $this->spa->manager_user_id);
        $this->assertSame('taken_over', $svc->state($this->spa));
        $this->assertSame('admin@example.test', $this->spa->decisionUser()->email);
        $this->assertTrue(ActivityLog::where('action', 'spa.management_taken_over')->exists());

        $booking = Booking::create(['spa_id' => $this->spa->id, 'reference' => 'HLTESTA1', 'status' => 'waiting', 'source' => 'online', 'start_at' => now()->addDays(2), 'end_at' => now()->addDays(2)->addHour(), 'party' => 1, 'duration_min' => 60, 'total' => 100, 'first_name' => 'C', 'last_name' => 'L', 'email' => 'client@example.test', 'phone' => '+212600000009', 'locale' => 'fr', 'quote' => ['lines' => []], 'manage_token' => str_repeat(strtolower(substr('HLTESTA1', -2)), 32), 'expires_at' => now()->addHours(2)]);
        try {
            app(BookingService::class)->accept($booking, 'partner');
            $this->fail('le partenaire ne doit pas décider en mode admin');
        } catch (BookingException $e) {
            $this->assertStringContainsString('géré par HammamLuxury', $e->getMessage());
        }
        try {
            app(BookingService::class)->decline($booking, 'partner', 'full');
            $this->fail('le partenaire ne doit pas refuser en mode admin');
        } catch (BookingException $e) {
            $this->assertStringContainsString('géré par HammamLuxury', $e->getMessage());
        }
        $this->assertSame('waiting', $booking->fresh()->status);

        // Le propriétaire garde l'accès en lecture à sa fiche, sans décider.
        $this->actingAs($this->owner)->get('/partenaire/'.$this->spa->slug)->assertOk();
        Filament::setCurrentPanel(Filament::getPanel('partner'));
        Filament::setTenant($this->spa, true);
        Livewire::test(ViewBooking::class, ['record' => $booking->id])
            ->assertActionHidden('accept')->assertActionHidden('decline');
        Livewire::test(ListBookings::class)
            ->assertTableActionHidden('accept', $booking)->assertTableActionHidden('decline', $booking);

        $this->actingAs($this->admin);
        $svc->handBack($this->spa);
        $this->spa->refresh();
        $this->assertSame('partner', $this->spa->managed_by);
        $this->assertNull($this->spa->manager_user_id);
        $this->assertSame('owner@example.test', $this->spa->decisionUser()->email);
        $this->assertTrue(ActivityLog::where('action', 'spa.management_handed_back')->exists());
    }

    public function test_new_request_notification_goes_to_the_manager_when_taken_over(): void
    {
        app(OwnerAssignmentService::class)->takeOver($this->spa, $this->admin);
        $booking = Booking::create(['spa_id' => $this->spa->id, 'reference' => 'HLTESTA2', 'status' => 'waiting', 'source' => 'online', 'start_at' => now()->addDays(2), 'end_at' => now()->addDays(2)->addHour(), 'party' => 1, 'duration_min' => 60, 'total' => 100, 'first_name' => 'C', 'last_name' => 'L', 'email' => 'client@example.test', 'phone' => '+212600000009', 'locale' => 'fr', 'quote' => ['lines' => []], 'manage_token' => str_repeat(strtolower(substr('HLTESTA2', -2)), 32), 'expires_at' => now()->addHours(2)]);
        event(new BookingCreated($booking));
        $partnerSide = BookingNotification::where('booking_id', $booking->id)->where('audience', 'partner')->first();
        $this->assertNotNull($partnerSide);
        $this->assertSame('admin@example.test', $partnerSide->recipient);
        $this->assertSame(0, BookingNotification::where('booking_id', $booking->id)->where('recipient', 'owner@example.test')->count());

        // Confirmation par l'Admin pendant la reprise : la copie « établissement » part aussi au gestionnaire, pas au propriétaire.
        $booking->update(['status' => 'confirmed', 'confirmed_at' => now()]);
        event(new BookingStatusChanged($booking->fresh(), 'waiting'));
        $this->assertSame(0, BookingNotification::where('booking_id', $booking->id)->where('recipient', 'owner@example.test')->count());
        $this->assertGreaterThanOrEqual(2, BookingNotification::where('booking_id', $booking->id)->where('recipient', 'admin@example.test')->count());

        // Restitution : une nouvelle demande revient au propriétaire, plus rien au gestionnaire.
        app(OwnerAssignmentService::class)->handBack($this->spa->fresh());
        $again = Booking::create(['spa_id' => $this->spa->id, 'reference' => 'HLTESTA3', 'status' => 'waiting', 'source' => 'online', 'start_at' => now()->addDays(3), 'end_at' => now()->addDays(3)->addHour(), 'party' => 1, 'duration_min' => 60, 'total' => 100, 'first_name' => 'C', 'last_name' => 'L', 'email' => 'client@example.test', 'phone' => '+212600000009', 'locale' => 'fr', 'quote' => ['lines' => []], 'manage_token' => str_repeat('a3', 32), 'expires_at' => now()->addHours(2)]);
        event(new BookingCreated($again));
        $this->assertSame('owner@example.test', BookingNotification::where('booking_id', $again->id)->where('audience', 'partner')->value('recipient'));
        $this->assertSame(0, BookingNotification::where('booking_id', $again->id)->where('recipient', 'admin@example.test')->count());
    }

    public function test_suspension_and_take_over_are_scoped_to_one_spa_of_a_multi_spa_owner(): void
    {
        $svc = app(OwnerAssignmentService::class);
        $second = $svc->createSpa($this->admin, ['name' => 'Second Spa', 'category' => 'spa', 'city_id' => City::first()->id]);
        $svc->assign($second, ['first_name' => 'P', 'last_name' => 'O', 'email' => 'owner@example.test', 'company_name' => 'Owner SARL'], invite: false);
        $second->refresh();
        $this->assertSame($this->spa->partner_id, $second->partner_id, 'même propriétaire pour les deux fiches');

        $svc->suspendAccess($this->spa);
        $this->owner->refresh();
        $panel = Filament::getPanel('partner');
        $this->assertTrue($this->owner->canAccessPanel($panel), 'l’espace partenaire reste ouvert pour l’autre fiche');
        $this->assertFalse($this->owner->canAccessTenant($this->spa->fresh()));
        $this->assertTrue($this->owner->canAccessTenant($second));
        $this->assertSame([$second->id], $this->owner->getTenants($panel)->pluck('id')->all());
        $this->assertSame('partner', $svc->state($second));
        $this->actingAs($this->owner)->get('/partenaire/'.$second->slug)->assertOk();
        $this->assertContains($this->actingAs($this->owner)->get('/partenaire/'.$this->spa->slug)->status(), [403, 404], 'fiche suspendue inaccessible');

        $this->actingAs($this->admin);
        $svc->reactivateAccess($this->spa);
        $svc->takeOver($second, $this->admin);
        $this->assertSame('owner@example.test', $this->spa->fresh()->decisionUser()->email, 'la reprise de l’autre fiche ne change rien ici');
        $this->assertSame('admin@example.test', $second->fresh()->decisionUser()->email);
        $this->assertSame('partner', $svc->state($this->spa->fresh()));
        $this->assertSame('taken_over', $svc->state($second->fresh()));

        // Toutes les fiches suspendues → plus d'accès du tout ; un compte sans fiche garde l'accès (inscription).
        $svc->handBack($second);
        $svc->suspendAccess($this->spa);
        $svc->suspendAccess($second);
        $this->assertFalse($this->owner->fresh()->canAccessPanel($panel));
        $fresh = User::create(['name' => 'Nouveau', 'email' => 'nouveau@example.test', 'password' => 'secret-test', 'role' => 'partner']);
        $this->assertTrue($fresh->canAccessPanel($panel));
    }

    public function test_suspending_access_blocks_the_owner_login_and_keeps_data_until_reactivation(): void
    {
        $svc = app(OwnerAssignmentService::class);
        $svc->suspendAccess($this->spa, 'impayé');
        $this->spa->refresh();
        $this->assertTrue($this->spa->ownerAccessSuspended());
        $this->assertSame('suspended', $svc->state($this->spa));
        $this->assertFalse($this->owner->canAccessPanel(Filament::getPanel('partner')));
        $this->assertFalse($this->owner->canAccessTenant($this->spa));
        $this->assertSame($this->owner->partner->id, $this->spa->fresh()->partner_id, 'la fiche reste au propriétaire');
        $this->assertTrue(ActivityLog::where('action', 'spa.owner_access_suspended')->exists());

        $this->actingAs($this->owner)->get('/partenaire/'.$this->spa->slug)->assertForbidden();

        $this->actingAs($this->admin);
        $svc->reactivateAccess($this->spa);
        $this->spa->refresh();
        $this->assertFalse($this->spa->ownerAccessSuspended());
        $this->assertTrue($this->owner->canAccessPanel(Filament::getPanel('partner')));
        $this->assertTrue($this->admin->canAccessPanel(Filament::getPanel('partner')));
    }

    public function test_resend_invitation_and_update_owner_details(): void
    {
        $svc = app(OwnerAssignmentService::class);
        $svc->resendInvite($this->spa);
        Mail::assertQueued(PartnerInviteMail::class, 2);

        $svc->updateOwner($this->spa, ['first_name' => 'Youssef', 'last_name' => 'Alami', 'email' => 'Nouveau@Example.test', 'phone' => '+212611111111']);
        $this->owner->refresh();
        $this->assertSame('nouveau@example.test', $this->owner->email);
        $this->assertSame('Youssef Alami', $this->owner->name);
        $this->assertSame('+212611111111', $this->owner->phone);
        $this->assertSame('+212611111111', $this->owner->partner->fresh()->contact_phone);
        $this->assertTrue(ActivityLog::where('action', 'spa.owner_updated')->exists());

        try {
            $svc->updateOwner($this->spa, ['first_name' => 'Y', 'last_name' => 'A', 'email' => 'admin@example.test']);
            $this->fail('un e-mail déjà pris doit être refusé');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('email', $e->errors());
        }

        $portfolio = $svc->createSpa($this->admin, ['name' => 'Sans proprio', 'category' => 'hammam', 'city_id' => City::first()->id]);
        $this->expectException(ValidationException::class);
        $svc->resendInvite($portfolio);
    }

    public function test_admin_list_shows_management_badge_and_actions(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ListSpas::class)
            ->assertSee(__('admin.mgmt_state_partner'))
            ->callTableAction('take_over', $this->spa, ['note' => 'test'])
            ->assertHasNoTableActionErrors();
        $this->assertSame('admin', $this->spa->fresh()->managed_by);
        Livewire::test(ListSpas::class)
            ->assertSee(__('admin.mgmt_state_taken_over'))
            ->callTableAction('hand_back', $this->spa->fresh())
            ->assertHasNoTableActionErrors();
        $this->assertSame('partner', $this->spa->fresh()->managed_by);
        Livewire::test(ListSpas::class)
            ->callTableAction('suspend_access', $this->spa->fresh(), ['note' => ''])
            ->assertHasNoTableActionErrors();
        $this->assertTrue($this->spa->fresh()->ownerAccessSuspended());
        Livewire::test(ListSpas::class)
            ->callTableAction('edit_owner', $this->spa->fresh(), ['first_name' => 'P', 'last_name' => 'O', 'email' => 'admin@example.test', 'phone' => null])
            ->assertHasTableActionErrors(['email']);
        $this->assertSame('owner@example.test', $this->owner->fresh()->email);
        auth()->logout();
        $this->get('/partenaire/register')->assertOk();
    }

    public function test_unpublished_spa_is_previewable_only_by_a_logged_in_admin(): void
    {
        $svc = app(OwnerAssignmentService::class);
        $draft = $svc->createSpa($this->admin, ['name' => 'Fiche en préparation', 'category' => 'hammam', 'city_id' => City::first()->id]);
        $this->assertNotSame('published', $draft->status);

        auth()->logout();
        $this->get('/fr/spa/'.$draft->slug)->assertNotFound();
        $this->actingAs($this->owner)->get('/fr/spa/'.$draft->slug)->assertNotFound();

        $this->actingAs($this->admin)->get('/fr/spa/'.$draft->slug)->assertOk()->assertSee(__('ui.preview_private_title'))
            ->assertDontSee('/spa/'.$draft->slug.'/reserver')->assertSee(__('ui.preview_no_booking'));
        $this->actingAs($this->admin)->get('/fr/spa/'.$draft->slug.'/reserver')->assertNotFound();
    }

    public function test_directions_button_targets_the_google_listing_when_a_place_id_is_known(): void
    {
        $svc = app(OwnerAssignmentService::class);
        $spa = $svc->createSpa($this->admin, ['name' => 'Isis Spa', 'category' => 'hammam', 'city_id' => City::first()->id]);
        $spa->update(['lat' => 31.6267716, 'lng' => -7.9841938]);

        $this->assertSame('https://www.google.com/maps/dir/?api=1&destination=31.6267716%2C-7.9841938&travelmode=walking', $spa->fresh()->directionsUrl());
        $this->assertSame('https://www.google.com/maps/search/?api=1&query=31.6267716%2C-7.9841938', $spa->fresh()->mapsUrl());

        $spa->update(['google_maps_url' => 'https://maps.app.goo.gl/WNpXLEEMiEWq7Z2x7', 'google_place_id' => 'ChIJG-_M9WDurw0RzaspL7K95Ss']);
        $spa->refresh();
        $this->assertSame('https://www.google.com/maps/dir/?api=1&destination=Isis+Spa&destination_place_id=ChIJG-_M9WDurw0RzaspL7K95Ss&travelmode=walking', $spa->directionsUrl());
        $this->assertSame('https://maps.app.goo.gl/WNpXLEEMiEWq7Z2x7', $spa->mapsUrl());

        $html = $this->actingAs($this->admin)->get('/fr/spa/'.$spa->slug)->assertOk()->getContent();
        $this->assertStringContainsString('destination_place_id=ChIJG-_M9WDurw0RzaspL7K95Ss', $html);
        $this->assertStringContainsString('https://maps.app.goo.gl/WNpXLEEMiEWq7Z2x7', $html);
        $this->assertStringContainsString('data-lat="31.6267716"', $html, 'le marqueur de la carte intégrée reste sur la position enregistrée');
        $this->assertStringNotContainsString('destination=31.6267716', $html);

        $spa->update(['google_place_id' => null, 'google_maps_url' => null, 'lat' => null, 'lng' => null]);
        $this->assertNull($spa->fresh()->directionsUrl());
        $this->assertNull($spa->fresh()->mapsUrl());
    }
}
