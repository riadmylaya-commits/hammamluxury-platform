<?php

namespace Tests\Engine;

use App\Filament\Partner\Pages\Register;
use App\Models\Partner;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Auth\VerifyEmail;
use Filament\Pages\Auth\EmailVerification\EmailVerificationPrompt;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/** Inscription partenaire : compte en attente, e-mail à confirmer avant tout accès à l'espace, renvoi possible. */
class PartnerRegistrationTest extends BookingFlowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('partner'));
    }

    public function test_registration_creates_pending_partner_and_sends_verification_email(): void
    {
        Notification::fake();

        Livewire::test(Register::class)->fillForm([
            'name' => 'Nadia',
            'company_name' => 'Hammam Nadia SARL',
            'phone' => '+212661351989',
            'email' => 'nadia@example.test',
            'password' => 'motdepasse-solide',
            'passwordConfirmation' => 'motdepasse-solide',
        ])->call('register')->assertHasNoFormErrors();

        $user = User::where('email', 'nadia@example.test')->firstOrFail();
        $this->assertSame('partner', $user->role);
        $this->assertSame('+212661351989', $user->phone);
        $this->assertSame('+212661351989', Partner::where('user_id', $user->id)->value('contact_phone'));
        $this->assertNull($user->email_verified_at);
        $this->assertSame('pending', Partner::where('user_id', $user->id)->value('status'));
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->assertSame('fr', $user->preferredLocale());
        $this->assertSame('Confirmez votre adresse e-mail', __('Verify Email Address', [], 'fr'));
    }

    public function test_unverified_partner_is_sent_to_verification_prompt_and_can_resend(): void
    {
        Notification::fake();
        $user = User::create(['name' => 'Sans mail', 'email' => 'nv@example.test', 'password' => 'secret-test', 'role' => 'partner']);
        Partner::create(['user_id' => $user->id, 'company_name' => 'NV', 'status' => 'pending']);

        $this->actingAs($user)->get('/partenaire')->assertRedirect();
        $this->actingAs($user)->get('/partenaire/new')->assertRedirect('/partenaire/email-verification/prompt');
        $this->actingAs($user)->get('/partenaire/email-verification/prompt')->assertOk();

        Livewire::actingAs($user)->test(EmailVerificationPrompt::class)->callAction('resendNotification');
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_signed_link_verifies_email_and_opens_partner_area(): void
    {
        $user = User::create(['name' => 'À vérifier', 'email' => 'av@example.test', 'password' => 'secret-test', 'role' => 'partner']);
        Partner::create(['user_id' => $user->id, 'company_name' => 'AV', 'status' => 'pending']);

        $url = URL::temporarySignedRoute('filament.partner.auth.email-verification.verify', now()->addMinutes(60), ['id' => $user->getKey(), 'hash' => sha1($user->email)]);
        $this->actingAs($user)->get($url)->assertRedirect();

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->actingAs($user)->get('/partenaire/new')->assertOk();
    }
}
