<?php

namespace Tests\Engine;

use App\Models\BookingNotification;
use Illuminate\Support\Facades\Mail;

/** hl:notifications-retry --alert : une seule alerte par nouvel échec définitif, plus de rappel toutes les 30 minutes. */
class NotificationAlertOnceTest extends BookingFlowTestCase
{
    private int $base = 0;

    /** Alertes envoyées depuis la soumission (les e-mails de la réservation elle-même sont exclus). */
    private function alertsSent(): int
    {
        return count($this->messages()) - $this->base;
    }

    private function messages(): array
    {
        return app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->all();
    }

    private function exhaust(BookingNotification $n): void
    {
        $n->update(['status' => 'failed', 'attempts' => 6, 'failed_at' => now(), 'error' => '550 No Such User Here']);
    }

    public function test_alert_is_sent_once_per_new_definitive_failure(): void
    {
        config(['mail.default' => 'array', 'hl.alert_email' => 'alerte@hammamluxury.test']);
        app()->forgetInstance('mail.manager');
        Mail::clearResolvedInstances();
        $b = $this->submit($this->intent('10:00', ['treatment' => $this->m->id, 'party' => 1]))['booking'];
        $this->base = count($this->messages());
        [$client, $partner] = BookingNotification::where('booking_id', $b->id)->orderBy('id')->take(2)->get()->all();
        $this->exhaust($partner);

        $this->artisan('hl:notifications-retry --alert')->assertFailed();
        $this->assertSame(1, $this->alertsSent(), 'première alerte pour le nouvel incident');
        $this->assertNotNull($partner->fresh()->alerted_at);
        $this->assertSame('failed', $partner->fresh()->status, 'la notification reste en échec, visible dans l’Admin');
        $this->assertStringContainsString('1 nouvelle(s)', $this->messages()[$this->base]->getOriginalMessage()->getSubject());

        $this->artisan('hl:notifications-retry --alert')->assertSuccessful();
        $this->artisan('hl:notifications-retry --alert')->assertSuccessful();
        $this->assertSame(1, $this->alertsSent(), 'aucun rappel pour le même incident');

        $this->exhaust($client);
        $this->artisan('hl:notifications-retry --alert')->assertFailed();
        $this->assertSame(2, $this->alertsSent(), 'une alerte pour le nouvel incident uniquement');
        $this->assertStringContainsString('1 nouvelle(s)', $this->messages()[$this->base + 1]->getOriginalMessage()->getSubject());
        $this->assertStringContainsString('(2 au total)', $this->messages()[$this->base + 1]->getOriginalMessage()->getSubject());
        $this->assertNotNull($client->fresh()->alerted_at);
    }

    public function test_without_alert_option_nothing_is_sent_but_new_failures_are_marked(): void
    {
        config(['mail.default' => 'array']);
        app()->forgetInstance('mail.manager');
        Mail::clearResolvedInstances();
        $b = $this->submit($this->intent('10:00', ['treatment' => $this->m->id, 'party' => 1]))['booking'];
        $this->base = count($this->messages());
        $n = BookingNotification::where('booking_id', $b->id)->orderBy('id')->firstOrFail();
        $this->exhaust($n);

        $this->artisan('hl:notifications-retry')->assertFailed();
        $this->assertSame(0, $this->alertsSent());
        $this->assertNotNull($n->fresh()->alerted_at);
        $this->artisan('hl:notifications-retry')->assertSuccessful();
    }
}
