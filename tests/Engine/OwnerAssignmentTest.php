<?php

namespace Tests\Engine;

use App\Domain\Partner\OwnerAssignmentService;
use App\Filament\Admin\Resources\SpaResource\Pages\ListSpas;
use App\Mail\PartnerInviteMail;
use App\Models\ActivityLog;
use App\Models\City;
use App\Models\Partner;
use App\Models\Spa;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Fiche créée par l'Admin, configurée dans son portefeuille, puis attribuée au véritable propriétaire. */
class OwnerAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private City $city;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'secret-test', 'role' => 'admin', 'email_verified_at' => now()]);
        $this->city = City::firstOrCreate(['slug' => 'marrakech'], ['name_fr' => 'Marrakech', 'name_en' => 'Marrakech']);
        $this->actingAs($this->admin);
    }

    public function test_admin_creates_a_draft_spa_in_its_own_portfolio_and_is_redirected_to_manage_it(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ListSpas::class)
            ->callAction('create_spa', ['name' => 'Mythic Oriental Spa', 'category' => 'hammam-massage', 'city_id' => $this->city->id, 'address' => '7 Derb Esaleh', 'phone' => '0524384161', 'email' => 'info@spa.test'])
            ->assertHasNoActionErrors()
            ->assertRedirect('/partenaire/mythic-oriental-spa-marrakech');

        $spa = Spa::firstOrFail();
        $holding = Partner::where('user_id', $this->admin->id)->firstOrFail();
        $this->assertSame('draft', $spa->status);
        $this->assertSame($holding->id, $spa->partner_id);
        $this->assertSame(OwnerAssignmentService::HOLDING_COMPANY, $holding->company_name);
        $this->assertSame('Marrakech', $spa->city);
        $this->assertSame('+212524384161', $spa->phone);

        $this->get('/partenaire/'.$spa->slug)->assertOk()->assertSee('Mythic Oriental Spa');
        $this->assertTrue(ActivityLog::where('action', 'spa.created_by_admin')->exists());
    }

    public function test_assigning_creates_the_owner_account_moves_the_spa_and_sends_an_invitation_valid_24h(): void
    {
        Mail::fake();
        $spa = $this->draftSpa();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ListSpas::class)
            ->callTableAction('assign_owner', $spa, ['first_name' => 'Youssef', 'last_name' => 'Alami', 'email' => 'Proprio@Mythic.test', 'phone' => '0666947278', 'company_name' => 'Mythic Oriental Spa SARL', 'invite' => true])
            ->assertHasNoTableActionErrors();

        $owner = User::where('email', 'proprio@mythic.test')->firstOrFail();
        $partner = Partner::where('user_id', $owner->id)->firstOrFail();
        $this->assertSame('partner', $owner->role);
        $this->assertSame('+212666947278', $owner->phone);
        $this->assertNotNull($owner->email_verified_at);
        $this->assertSame('approved', $partner->status);
        $this->assertSame('Mythic Oriental Spa SARL', $partner->company_name);
        $this->assertSame($partner->id, $spa->fresh()->partner_id);

        Mail::assertQueued(PartnerInviteMail::class, fn (PartnerInviteMail $m) => $m->hasTo('proprio@mythic.test') && $m->spa->is($spa));
        $this->assertSame(1440, config('auth.passwords.partners.expire'));
        $this->assertTrue(Password::broker('partners')->getRepository()->exists($owner, Password::broker('partners')->createToken($owner)));
        $this->assertTrue(ActivityLog::where('action', 'spa.owner_assigned')->exists());
        $this->assertTrue(ActivityLog::where('action', 'spa.owner_invited')->exists());

        // Le lien d'invitation pointe vers la page de réinitialisation de l'espace partenaire.
        $mail = new PartnerInviteMail($owner, $spa, 'tok');
        $this->assertStringContainsString('/partenaire/password-reset/reset?', $mail->content()->with['url']);
    }

    public function test_owner_only_sees_its_own_spa(): void
    {
        Mail::fake();
        $spa = $this->draftSpa();
        $otherUser = User::create(['name' => 'Autre', 'email' => 'autre@example.test', 'password' => 'secret-test', 'role' => 'partner', 'email_verified_at' => now()]);
        $otherPartner = Partner::create(['user_id' => $otherUser->id, 'company_name' => 'Autre SARL', 'status' => 'approved']);
        $otherSpa = Spa::create(['partner_id' => $otherPartner->id, 'slug' => 'autre-spa', 'name' => 'Autre Spa', 'city' => 'Fès', 'status' => 'published']);

        app(OwnerAssignmentService::class)->assign($spa, ['first_name' => 'Y', 'last_name' => 'A', 'email' => 'proprio@mythic.test', 'phone' => '+212666947278', 'company_name' => 'Mythic SARL'], invite: false);
        $owner = User::where('email', 'proprio@mythic.test')->firstOrFail();

        $this->actingAs($owner)->get('/partenaire/'.$spa->slug)->assertOk();
        $this->actingAs($owner)->get('/partenaire/'.$otherSpa->slug)->assertNotFound();
        $this->actingAs($owner)->get('/admin')->assertForbidden();
        $this->assertSame([$spa->id], $owner->getTenants(Filament::getPanel('partner'))->pluck('id')->all());
        $this->assertTrue($this->admin->getTenants(Filament::getPanel('partner'))->contains($spa));
        $this->assertTrue($this->admin->canAccessTenant($spa) && $this->admin->canAccessTenant($otherSpa));
        $this->assertFalse($owner->canAccessTenant($otherSpa));
        Mail::assertNothingQueued();
    }

    public function test_the_modal_shows_the_error_when_the_email_belongs_to_an_admin(): void
    {
        Mail::fake();
        $spa = $this->draftSpa();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ListSpas::class)
            ->callTableAction('assign_owner', $spa, ['first_name' => 'X', 'last_name' => 'Y', 'email' => 'admin@example.test', 'phone' => '0600000000', 'company_name' => 'X', 'invite' => true])
            ->assertHasTableActionErrors(['email']);
        $this->assertSame(Partner::where('user_id', $this->admin->id)->value('id'), $spa->fresh()->partner_id);
        Mail::assertNothingQueued();
    }

    public function test_admin_keeps_full_access_after_assignment(): void
    {
        $spa = $this->draftSpa();
        app(OwnerAssignmentService::class)->assign($spa, ['first_name' => 'Y', 'last_name' => 'A', 'email' => 'proprio@mythic.test', 'phone' => '+212666947278', 'company_name' => 'Mythic SARL'], invite: false);

        $this->get('/admin/spas')->assertOk()->assertSee('Mythic Oriental Spa')->assertSee('Mythic SARL');
        $this->get('/partenaire/'.$spa->slug)->assertOk();
    }

    public function test_assigning_to_an_existing_partner_reuses_its_account_and_refuses_admin_or_client_emails(): void
    {
        Mail::fake();
        $spa = $this->draftSpa();
        $existing = User::create(['name' => 'Déjà', 'email' => 'deja@example.test', 'password' => 'secret-test', 'role' => 'partner', 'email_verified_at' => now()]);
        $existingPartner = Partner::create(['user_id' => $existing->id, 'company_name' => 'Déjà SARL', 'status' => 'approved']);

        $partner = app(OwnerAssignmentService::class)->assign($spa, ['first_name' => 'X', 'last_name' => 'Y', 'email' => 'deja@example.test', 'phone' => '+212600000000', 'company_name' => 'Ignoré']);
        $this->assertSame($existingPartner->id, $partner->id);
        $this->assertSame('Déjà SARL', $partner->fresh()->company_name);
        $this->assertSame(1, User::where('email', 'deja@example.test')->count());
        $this->assertSame($existingPartner->id, $spa->fresh()->partner_id);
        Mail::assertQueued(PartnerInviteMail::class, 1);

        User::create(['name' => 'Client', 'email' => 'client@example.test', 'password' => 'secret-test', 'role' => 'client']);
        try {
            app(OwnerAssignmentService::class)->assign($spa, ['first_name' => 'X', 'last_name' => 'Y', 'email' => 'client@example.test', 'phone' => '+212600000000', 'company_name' => 'C']);
            $this->fail('Un e-mail client ne doit pas être accepté.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('email', $e->errors());
        }
        try {
            app(OwnerAssignmentService::class)->assign($spa, ['first_name' => 'X', 'last_name' => 'Y', 'email' => 'admin@example.test', 'phone' => '+212600000000', 'company_name' => 'A']);
            $this->fail('Un e-mail admin ne doit pas être accepté.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('email', $e->errors());
        }
        $this->assertSame($existingPartner->id, $spa->fresh()->partner_id);
    }

    public function test_only_admins_get_a_portfolio_partner(): void
    {
        $partnerUser = User::create(['name' => 'P', 'email' => 'p@example.test', 'password' => 'secret-test', 'role' => 'partner']);
        $this->expectException(HttpException::class);
        app(OwnerAssignmentService::class)->holdingPartner($partnerUser);
    }

    private function draftSpa(): Spa
    {
        return app(OwnerAssignmentService::class)->createSpa($this->admin, ['name' => 'Mythic Oriental Spa', 'category' => 'hammam-massage', 'city_id' => $this->city->id]);
    }
}
