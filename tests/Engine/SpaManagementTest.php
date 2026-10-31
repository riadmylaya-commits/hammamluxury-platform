<?php

namespace Tests\Engine;

use App\Domain\Booking\BookingException;
use App\Domain\Booking\BookingService;
use App\Domain\Partner\OwnerAssignmentService;
use App\Events\BookingCreated;
use App\Filament\Admin\Resources\SpaResource\Pages\ListSpas;
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

        // Le propriétaire garde l'accès en lecture à sa fiche.
        $this->actingAs($this->owner)->get('/partenaire/'.$this->spa->slug)->assertOk();

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
    }

    public function test_suspending_access_blocks_the_owner_login_and_keeps_data_until_reactivation(): void
    {
        $svc = app(OwnerAssignmentService::class);
        $svc->suspendAccess($this->spa, 'impayé');
        $this->owner->refresh();
        $this->assertTrue($this->owner->accessSuspended());
        $this->assertSame('suspended', $svc->state($this->spa->fresh()));
        $this->assertFalse($this->owner->canAccessPanel(Filament::getPanel('partner')));
        $this->assertFalse($this->owner->canAccessTenant($this->spa));
        $this->assertSame($this->owner->partner->id, $this->spa->fresh()->partner_id, 'la fiche reste au propriétaire');
        $this->assertTrue(ActivityLog::where('action', 'spa.owner_access_suspended')->exists());

        $this->actingAs($this->owner)->get('/partenaire/'.$this->spa->slug)->assertForbidden();

        $this->actingAs($this->admin);
        $svc->reactivateAccess($this->spa);
        $this->owner->refresh();
        $this->assertFalse($this->owner->accessSuspended());
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
        $this->assertTrue($this->owner->fresh()->accessSuspended());
        auth()->logout();
        $this->get('/partenaire/register')->assertOk();
    }
}
