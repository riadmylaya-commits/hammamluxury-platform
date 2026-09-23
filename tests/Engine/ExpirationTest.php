<?php

namespace Tests\Engine;

use App\Events\BookingExpired;
use App\Mail\BookingMail;
use App\Models\Allocation;
use App\Models\Booking;
use Illuminate\Support\Facades\Mail;

class ExpirationTest extends BookingFlowTestCase
{
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
        Mail::assertNothingSent();

        Booking::where('id', $b->id)->update(['expires_at' => now()->subMinute()]);
        $this->assertSame([], $this->bookings->expireWaiting(), 'Ancienne échéance résiduelle sur une réservation expirée : ignorée');
        event(new BookingExpired($b->fresh()));
        event(new BookingExpired($b->fresh()));
        Mail::assertNothingSent();
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

    public function test_confirmed_bookings_never_expire(): void
    {
        $b = $this->submit($this->intent('10:00', ['treatment' => $this->h->id, 'party' => 1]))['booking'];
        $this->bookings->accept($b);
        Booking::where('id', $b->id)->update(['expires_at' => now()->subMinute()]);
        Mail::fake();
        $this->assertSame([], $this->bookings->expireWaiting(), 'Réservation confirmée avec échéance résiduelle : jamais expirée');
        Mail::assertNothingSent();
        $this->assertSame('confirmed', $b->fresh()->status);
    }
}
