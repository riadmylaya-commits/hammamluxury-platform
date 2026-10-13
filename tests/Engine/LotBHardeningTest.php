<?php

namespace Tests\Engine;

use App\Console\Commands\DemoGuard;
use App\Filament\Partner\Resources\TreatmentResource\Pages\EditTreatment;
use App\Filament\Partner\Resources\TreatmentResource\RelationManagers\ExtrasRelationManager;
use App\Jobs\SendBookingNotification;
use App\Listeners\SendBookingNotifications;
use App\Livewire\Site\BookingShow;
use App\Mail\BookingMail;
use App\Models\BookingNotification;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Symfony\Component\Mailer\Exception\TransportException;

/** Lot B — notifications traçables par destinataire, liens de suivi protégés, limites de débit, isolation tenant des extras, garde anti-démo. */
class LotBHardeningTest extends BookingFlowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('fr');
        RateLimiter::clear('booking-submit:127.0.0.1');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_each_recipient_has_one_traceable_notification_and_replays_do_not_duplicate(): void
    {
        Mail::fake();
        $b = $this->submit($this->intent('10:00', ['treatment' => $this->m->id, 'party' => 1]))['booking'];

        $rows = BookingNotification::where('booking_id', $b->id)->where('event', 'created')->get();
        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(['client', 'partner'], $rows->pluck('audience')->all());
        foreach ($rows as $n) {
            $this->assertSame('sent', $n->status, 'file synchrone en test : l’état final est « envoyée »');
            $this->assertNotNull($n->queued_at);
            $this->assertNotNull($n->sent_at);
            $this->assertSame(1, $n->attempts);
        }
        $this->assertSame(1, $b->events()->where('type', 'notified:created')->count());

        // Rejeu de la planification (même événement) : aucune nouvelle ligne, aucun nouvel e-mail.
        $this->assertNull(SendBookingNotifications::dispatch($b, 'created', 'client', $b->email));
        $this->assertSame(2, BookingNotification::where('booking_id', $b->id)->count());
        Mail::assertSentCount(2);
        Mail::assertNothingQueued();
    }

    public function test_failed_delivery_is_recorded_per_recipient_and_retried_without_duplicates(): void
    {
        Mail::fake();
        $b = $this->submit($this->intent('10:00', ['treatment' => $this->m->id, 'party' => 1]))['booking'];
        $n = BookingNotification::where('booking_id', $b->id)->where('audience', 'client')->firstOrFail();

        // Panne SMTP réelle (serveur injoignable) : le job note l'échec et relance l'exception (reprise par la file).
        app()->forgetInstance('mail.manager');
        Mail::clearResolvedInstances();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 1]]);
        $n->update(['status' => 'queued']); // rejeu du job après une première livraison : état réinitialisé par la file
        try {
            (new SendBookingNotification($n->id))->handle();
            $this->fail('L’échec doit remonter à la file');
        } catch (TransportException) {
        }
        $n->refresh();
        $this->assertSame('failed', $n->status);
        $this->assertSame(2, $n->attempts);
        $this->assertNotEmpty($n->error);
        $this->assertNotNull($n->failed_at);

        config(['mail.default' => 'array']);
        app()->forgetInstance('mail.manager');
        Mail::clearResolvedInstances();
        Mail::fake();
        $this->assertSame(1, SendBookingNotifications::retry());
        $n->refresh();
        $this->assertSame('sent', $n->status);
        $this->assertNull($n->error);
        Mail::assertSent(BookingMail::class, fn ($m) => $m->audience === 'client' && $m->hasTo($b->email));
        Mail::assertSentCount(1);
        Mail::assertNothingQueued();
        $this->assertSame(0, SendBookingNotifications::retry(), 'plus rien à relancer');
        $this->artisan('hl:notifications-retry')->assertSuccessful();
    }

    public function test_tracking_link_is_noindex_no_store_and_blocked_in_robots(): void
    {
        $b = $this->submit($this->intent('10:00', ['treatment' => $this->m->id, 'party' => 1]))['booking'];

        $this->get(route('booking.show', ['locale' => 'fr', 'token' => $b->manage_token]))->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/fr/recherche')->assertOk()->assertHeaderMissing('X-Robots-Tag');
        $this->assertStringContainsString('Disallow: /reservation/', file_get_contents(public_path('robots.txt')));
    }

    public function test_tracking_link_can_be_revoked_rotated_and_expires_after_the_service(): void
    {
        $b = $this->submit($this->intent('10:00', ['treatment' => $this->m->id, 'party' => 1]))['booking'];
        $old = $b->manage_token;
        $this->get(route('booking.show', ['locale' => 'fr', 'token' => $old]))->assertOk();

        $new = $b->rotateManageToken('admin');
        $this->assertNotSame($old, $new);
        $this->get(route('booking.show', ['locale' => 'fr', 'token' => $old]))->assertNotFound();
        $this->get(route('booking.show', ['locale' => 'fr', 'token' => $new]))->assertOk();
        $this->assertSame(1, $b->events()->where('type', 'manage_link:rotated')->count());

        $b->revokeManageToken('admin');
        $this->get(route('booking.show', ['locale' => 'fr', 'token' => $new]))->assertStatus(410);
        $this->assertFalse($b->fresh()->manageLinkIsValid());

        $b->update(['manage_token_revoked_at' => null]);
        CarbonImmutable::setTestNow($b->end_at->addDays(31));
        $this->assertFalse($b->fresh()->manageLinkIsValid());
        $this->get(route('booking.show', ['locale' => 'fr', 'token' => $new]))->assertStatus(410);
        CarbonImmutable::setTestNow($b->end_at->addDays(29));
        $this->get(route('booking.show', ['locale' => 'fr', 'token' => $new]))->assertOk();
    }

    public function test_tracking_page_actions_are_rate_limited_per_link_and_ip(): void
    {
        $b = $this->submit($this->intent('10:00', ['treatment' => $this->m->id, 'party' => 1]))['booking'];
        $this->bookings->accept($b, 'partner');
        $c = Livewire::test(BookingShow::class, ['token' => $b->manage_token]);
        for ($i = 1; $i <= 20; $i++) {
            $c->set('messageBody', "Message numéro {$i}")->call('sendMessage')->assertSet('error', '');
        }
        $c->set('messageBody', 'Un de trop')->call('sendMessage')->assertSet('error', __('ui.too_many_attempts'));
        $this->assertSame(20, $b->messages()->count());

        for ($i = 0; $i < 5; $i++) {
            RateLimiter::hit('booking-show:cancel:'.$b->id.':127.0.0.1', 3600);
        }
        $c->call('cancel')->assertSet('error', __('ui.too_many_attempts'));
        $this->assertSame('confirmed', $b->fresh()->status, 'la limite ne touche pas la réservation');
    }

    public function test_booking_submission_is_rate_limited_per_ip(): void
    {
        for ($i = 0; $i < 10; $i++) {
            RateLimiter::hit('booking-submit:127.0.0.1', 60);
        }
        $this->assertTrue(RateLimiter::tooManyAttempts('booking-submit:127.0.0.1', 10));
        $this->assertStringContainsString('RateLimiter::tooManyAttempts($key, 10)', file_get_contents(app_path('Livewire/Site/BookingFlow.php')));
    }

    public function test_partner_cannot_forge_spa_id_on_extras(): void
    {
        $mine = $this->spa;
        $this->spa('hl-test-autre', 'HL TEST Autre');
        $otherSpa = $this->spa;
        $this->spa = $mine;

        $this->actingAs($this->spa->partner->user);
        Filament::setCurrentPanel(Filament::getPanel('partner'));
        Filament::setTenant($this->spa, true);

        Livewire::test(ExtrasRelationManager::class, ['ownerRecord' => $this->m, 'pageClass' => EditTreatment::class])
            ->callTableAction('create', data: ['name_fr' => 'Gommage', 'price' => 50, 'extra_min' => 10, 'per_person' => true, 'max_qty' => 1, 'spa_id' => $otherSpa->id, 'treatment_id' => 999999])
            ->assertHasNoTableActionErrors();

        $extra = $this->m->extras()->where('name_fr', 'Gommage')->firstOrFail();
        $this->assertSame($this->spa->id, $extra->spa_id);
        $this->assertSame($this->m->id, $extra->treatment_id);
        $this->assertSame(0, $otherSpa->extras()->count());
    }

    public function test_demo_guard_detects_demo_accounts_and_fails_only_in_production(): void
    {
        $this->artisan('hl:demo-guard')->assertSuccessful();
        User::create(['name' => 'Démo', 'email' => 'owner@hammamluxury.test', 'password' => 'secret-test', 'role' => 'partner']);
        $this->assertContains('Compte de démonstration : owner@hammamluxury.test', DemoGuard::findings());
        $this->artisan('hl:demo-guard')->assertSuccessful();

        app()->detectEnvironment(fn () => 'production');
        $this->artisan('hl:demo-guard')->assertFailed();
        app()->detectEnvironment(fn () => 'testing');
    }
}
