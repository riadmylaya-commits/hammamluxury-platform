<?php

namespace Tests\Engine;

use App\Domain\Security\TwoFactor;
use App\Filament\Shared\Pages\TwoFactorChallenge;
use App\Filament\Shared\Pages\TwoFactorSetup;
use App\Models\ActivityLog;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\Auth\Login;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

/** Double authentification : obligatoire Admin, facultative Partenaire, codes de secours, journal des connexions. */
class TwoFactorTest extends BookingFlowTestCase
{
    private User $admin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['hl.security.admin_2fa_required' => true]);
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'secret-test', 'role' => 'admin', 'email_verified_at' => now()]);
        $this->owner = $this->spa->partner->user;
    }

    private function totp(string $secret): string
    {
        return (new Google2FA)->getCurrentOtp($secret);
    }

    /** @return list<string> */
    private function enableFor(User $user): array
    {
        $tf = app(TwoFactor::class);
        $secret = $tf->generateSecret();
        $codes = $tf->enable($user, $secret, $this->totp($secret));
        // Simule l'écoulement d'une période TOTP (l'activation a consommé le code courant).
        $user->forceFill(['two_factor_last_used_at' => (int) $user->two_factor_last_used_at - 2])->save();
        session()->flush();

        return [$secret, $codes];
    }

    public function test_admin_without_2fa_is_forced_to_setup_page(): void
    {
        $this->actingAs($this->admin)->get('/admin')->assertRedirect('/admin/securite');
        $this->actingAs($this->admin)->get('/admin/spas')->assertRedirect('/admin/securite');
        $this->actingAs($this->admin)->get('/admin/securite')->assertOk()->assertSee('obligatoire');
    }

    public function test_partner_without_2fa_is_not_blocked(): void
    {
        $this->actingAs($this->owner)->get('/partenaire/'.$this->spa->slug)->assertOk();
        $this->actingAs($this->owner)->get('/partenaire/securite')->assertOk()->assertSee('Activer');
    }

    public function test_admin_enables_2fa_with_a_valid_code_and_gets_recovery_codes(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin);

        $page = Livewire::test(TwoFactorSetup::class);
        $secret = $page->get('secret');
        $this->assertNotEmpty($secret);

        $page->fillForm(['code' => '000000'])->call('enable')->assertHasFormErrors(['code']);
        $this->assertFalse($this->admin->fresh()->hasTwoFactor());

        $page->fillForm(['code' => $this->totp($secret)])->call('enable')->assertHasNoFormErrors();
        $admin = $this->admin->fresh();
        $this->assertTrue($admin->hasTwoFactor());
        $this->assertCount(TwoFactor::RECOVERY_CODES, $page->get('recoveryCodes'));
        $this->assertCount(TwoFactor::RECOVERY_CODES, $admin->two_factor_recovery_codes);
        $this->assertNotSame($secret, $admin->getRawOriginal('two_factor_secret'), 'secret stocké chiffré');
        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.2fa_enabled', 'user_id' => $admin->id]);

        $this->get('/admin')->assertOk();
    }

    public function test_user_with_2fa_must_pass_challenge_each_session(): void
    {
        [$secret] = $this->enableFor($this->admin);

        $this->actingAs($this->admin)->get('/admin/spas')->assertRedirect('/admin/securite/verification');
        $this->get('/admin/securite/verification')->assertOk();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::test(TwoFactorChallenge::class);
        $page->fillForm(['code' => '123456'])->call('verify')->assertHasFormErrors(['code']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.2fa_failed', 'user_id' => $this->admin->id]);

        $page->fillForm(['code' => $this->totp($secret)])->call('verify')->assertHasNoFormErrors()->assertRedirect('/admin/spas');
        $this->get('/admin/spas')->assertOk();
        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.2fa_passed', 'user_id' => $this->admin->id]);
    }

    public function test_totp_code_cannot_be_replayed(): void
    {
        [$secret] = $this->enableFor($this->admin);
        $tf = app(TwoFactor::class);
        $code = $this->totp($secret);

        $this->assertTrue($tf->challenge($this->admin->fresh(), $code));
        session()->flush();
        $this->assertFalse($tf->challenge($this->admin->fresh(), $code), 'un code TOTP déjà utilisé est refusé');
    }

    public function test_recovery_code_works_once(): void
    {
        [, $codes] = $this->enableFor($this->owner);
        $tf = app(TwoFactor::class);

        $this->assertTrue($tf->challenge($this->owner->fresh(), $codes[0]));
        $this->assertCount(TwoFactor::RECOVERY_CODES - 1, $this->owner->fresh()->two_factor_recovery_codes);
        session()->flush();
        $this->assertFalse($tf->challenge($this->owner->fresh(), $codes[0]));
        $this->assertTrue($tf->challenge($this->owner->fresh(), $codes[1]));
    }

    public function test_partner_can_disable_but_admin_cannot(): void
    {
        [$secret] = $this->enableFor($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('partner'));
        $this->actingAs($this->owner);
        app(TwoFactor::class)->markPassed($this->owner);

        Livewire::test(TwoFactorSetup::class)
            ->callAction('disable', ['password' => 'wrong', 'code' => '000000'])->assertHasActionErrors(['password']);
        $this->assertTrue($this->owner->fresh()->hasTwoFactor());

        $this->owner->forceFill(['password' => 'secret-test'])->save();
        Livewire::test(TwoFactorSetup::class)
            ->callAction('disable', ['password' => 'secret-test', 'code' => $this->totp($secret)])->assertHasNoActionErrors();
        $this->assertFalse($this->owner->fresh()->hasTwoFactor());
        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.2fa_disabled', 'user_id' => $this->owner->id]);

        $this->enableFor($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin);
        app(TwoFactor::class)->markPassed($this->admin);
        Livewire::test(TwoFactorSetup::class)->assertActionHidden('disable');
    }

    public function test_login_attempts_are_journaled(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(Login::class)
            ->fillForm(['email' => $this->admin->email, 'password' => 'bad-password'])->call('authenticate');
        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.login_failed']);
        $this->assertSame(1, ActivityLog::where('action', 'auth.login_failed')->whereJsonContains('properties->email', $this->admin->email)->count());

        Livewire::test(Login::class)
            ->fillForm(['email' => $this->admin->email, 'password' => 'secret-test'])->call('authenticate');
        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.login', 'user_id' => $this->admin->id]);
    }
}
