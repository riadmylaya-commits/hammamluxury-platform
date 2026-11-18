<?php

namespace Tests\Engine;

use App\Domain\Booking\BookingException;
use App\Events\BookingExpired;
use App\Mail\BookingMail;
use App\Models\Allocation;
use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

class ExpirationTest extends BookingFlowTestCase
{
    public function test_night_request_on_a_partner_spa_expires_in_service_hours_and_emails_state_the_deadline(): void
    {
        config(['hl.admin_hours' => [9, 21], 'hl.waiting_ttl_hours' => 2]);
        $night = CarbonImmutable::parse($this->day)->subDays(3)->setTime(23, 49);
        $this->travelTo($night);
        $b = $this->submit($this->intent('17:00', ['treatment' => $this->h->id, 'party' => 1]))['booking'];
        $this->assertSame($night->addDay()->setTime(11, 0)->format('Y-m-d H:i'), $b->expires_at->format('Y-m-d H:i'), 'Demande de nuit : échéance à l’ouverture + 2 h');

        $this->travelTo($night->addDay()->setTime(8, 59));
        $this->assertSame([], $this->bookings->expireWaiting(), 'Rien n’expire pendant la nuit');

        $client = (new BookingMail($b, 'created', 'client'))->render();
        $this->assertStringContainsString('11h00', $client, 'E-mail client : heure limite indiquée');
        $partner = (new BookingMail($b, 'created', 'partner'))->render();
        $this->assertStringContainsString('11h00', $partner, 'E-mail établissement : heure limite indiquée');

        $this->travelTo($night->addDay()->setTime(11, 1));
        $this->assertSame([$b->id], $this->bookings->expireWaiting());
        $expired = (new BookingMail($b->fresh(), 'expired', 'client'))->render();
        $this->assertStringContainsString(route('search', ['locale' => 'fr']), $expired, 'E-mail d’expiration : lien vers la recherche d’un autre spa');
    }

    public function test_a_request_never_waits_past_the_appointment_start(): void
    {
        config(['hl.admin_hours' => [9, 21], 'hl.waiting_ttl_hours' => 2]);
        $this->travelTo(CarbonImmutable::parse($this->day)->setTime(8, 30));
        try {
            $this->intent('10:00', ['treatment' => $this->h->id, 'party' => 1]);
            $this->fail('Parcours public : un créneau qui ne laisse pas le délai de réponse doit être refusé');
        } catch (BookingException $e) {
            $this->assertSame('too_soon', $e->reason);
        }
        [$until, $lastMinute] = $this->bookings->requestDeadline($this->spa, CarbonImmutable::parse($this->day)->setTime(10, 0));
        $this->assertSame('10:00', $until->format('H:i'), 'Saisie hors parcours public : échéance ramenée à l’heure du rendez-vous');
        $this->assertFalse($lastMinute);
    }

    public function test_waiting_expires_once_and_releases(): void
    {
        $sel = ['treatment' => $this->m->id, 'party' => 2];
        $b = $this->submit($this->intent('17:00', $sel))['booking'];
        $this->assertNotContains($b->id, $this->bookings->expireWaiting(), 'Cron expiration : rien à expirer avant l’échéance');

        Booking::where('id', $b->id)->update(['expires_at' => now()->subMinute()]);
        Mail::fake();
        $done = $this->bookings->expireWaiting();
        $b->refresh();
        $this->assertTrue([$b->id] === $done && $b->status === 'expired' && $this->activeAllocations($b) === [] && Allocation::where('booking_id', $b->id)->where('status', 'released')->count() === 2, 'Échéance dépassée : statut expired, 2 allocations passées en released (historique conservé)');
        $this->assertNotNull($b->expiration_notified_at, 'Horodatage de notification d’expiration');
        Mail::assertSentCount(2);
        Mail::assertSent(BookingMail::class, fn ($m) => $m->event === 'expired' && $m->audience === 'client' && $m->hasTo('client@example.test'));
        Mail::assertSent(BookingMail::class, fn ($m) => $m->event === 'expired' && $m->audience === 'partner');

        Mail::fake();
        $this->assertSame([], $this->bookings->expireWaiting(), 'Second passage du cron : aucun retraitement');
        Mail::assertNothingOutgoing();

        Booking::where('id', $b->id)->update(['expires_at' => now()->subMinute()]);
        $this->assertSame([], $this->bookings->expireWaiting(), 'Ancienne échéance résiduelle sur une réservation expirée : ignorée');
        event(new BookingExpired($b->fresh()));
        event(new BookingExpired($b->fresh()));
        Mail::assertNothingOutgoing();
        $this->assertSame(1, $b->events()->where('type', 'notified:expired')->count(), 'Événement d’expiration répété : aucune seconde notification');

        $this->assertContains('17:00', $this->availability($sel)['times'], 'Créneau 17:00 massage ×2 redevenu disponable après expiration');
    }

    public function test_artisan_command_and_configurable_ttl(): void
    {
        config(['hl.waiting_ttl_hours' => 0.5]);
        $b = $this->submit($this->intent('10:00', ['treatment' => $this->h->id, 'party' => 1]))['booking'];
        $delta = $b->expires_at->diffInMinutes(now(), true);
        $this->assertTrue($delta > 28 && $delta < 32, 'Délai d’attente configurable : 0,5 h → '.round($delta).' min');
        Booking::where('id', $b->id)->update(['expires_at' => now()->subMinute()]);
        $this->artisan('hl:expire-waiting')->expectsOutputToContain('1 demande(s) expirée(s)')->assertSuccessful();
        $this->assertSame('expired', $b->fresh()->status, 'Commande artisan : demande expirée');
        $this->artisan('hl:expire-waiting')->expectsOutputToContain('0 demande(s)')->assertSuccessful();
    }

    public function test_spa_specific_waiting_ttl_overrides_global_value(): void
    {
        config(['hl.waiting_ttl_hours' => 2, 'hl.admin_hours' => [0, 24]]);
        $this->spa->update(['waiting_ttl_hours' => 12]);
        $b = $this->submit($this->intent('17:00', ['treatment' => $this->h->id, 'party' => 1]))['booking'];
        $delta = $b->expires_at->diffInMinutes(now(), true);
        $this->assertTrue($delta > 718 && $delta < 722, 'Délai propre à l’établissement : 12 h → '.round($delta).' min');

        $mail = (new BookingMail($b, 'created', 'partner'))->render();
        $this->assertStringContainsString('12', $mail, 'E-mail « nouvelle demande » : délai propre à l’établissement');
        $this->assertStringNotContainsString(' 2 h', $mail);

        $this->spa->update(['waiting_ttl_hours' => null]);
        $b = $this->submit($this->intent('18:00', ['treatment' => $this->h->id, 'party' => 1]))['booking'];
        $delta = $b->expires_at->diffInMinutes(now(), true);
        $this->assertTrue($delta > 118 && $delta < 122, 'Sans valeur propre : retour au délai global de 2 h → '.round($delta).' min');
    }

    public function test_confirmed_bookings_never_expire(): void
    {
        $b = $this->submit($this->intent('10:00', ['treatment' => $this->h->id, 'party' => 1]))['booking'];
        $this->bookings->accept($b);
        Booking::where('id', $b->id)->update(['expires_at' => now()->subMinute()]);
        Mail::fake();
        $this->assertSame([], $this->bookings->expireWaiting(), 'Réservation confirmée avec échéance résiduelle : jamais expirée');
        Mail::assertNothingOutgoing();
        $this->assertSame('confirmed', $b->fresh()->status);
    }
}
