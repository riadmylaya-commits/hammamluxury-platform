<?php

namespace App\Console\Commands;

use App\Domain\Billing\BillingNotifier;
use App\Listeners\SendBookingNotifications;
use App\Models\BillingNotification;
use App\Models\BookingNotification;
use Illuminate\Console\Command;

class RetryBookingNotifications extends Command
{
    protected $signature = 'hl:notifications-retry {--max=5 : Nombre maximal de tentatives par destinataire} {--alert : Alerte e-mail une seule fois par nouvel échec définitif}';

    protected $description = 'Remet en file les notifications de réservation et de facturation échouées ou perdues (par destinataire, sans doublon)';

    public function handle(): int
    {
        $max = (int) $this->option('max');
        $retried = SendBookingNotifications::retry($max) + app(BillingNotifier::class)->retry($max);

        $exhausted = fn (string $model) => $model::where('status', 'failed')->where('attempts', '>=', $max);
        $total = $exhausted(BookingNotification::class)->count() + $exhausted(BillingNotification::class)->count();
        $new = $exhausted(BookingNotification::class)->whereNull('alerted_at')->get()
            ->concat($exhausted(BillingNotification::class)->whereNull('alerted_at')->get());
        $this->info("Relancées : {$retried} ; échecs définitifs : {$total} (nouveaux : {$new->count()})");

        if ($new->isEmpty()) {
            return self::SUCCESS;
        }

        if ($this->option('alert')) {
            $lines = $new->map(fn ($n) => sprintf('- %s #%d : %s → %s (%s)',
                $n instanceof BillingNotification ? 'facturation' : 'réservation', $n->getKey(),
                $n->event ?? '', $n->recipient, mb_substr((string) $n->error, 0, 120)));
            $this->call('hl:alert', [
                'subject' => "[HL] {$new->count()} nouvelle(s) notification(s) en échec définitif ({$total} au total)",
                'body' => "Nouveaux échecs définitifs (aucune nouvelle alerte ne sera envoyée pour ceux-ci) :\n".$lines->implode("\n")
                    ."\nVoir booking_notifications et billing_notifications (status=failed).",
            ]);
        }
        $new->each(fn ($n) => $n->forceFill(['alerted_at' => now()])->saveQuietly());

        return self::FAILURE;
    }
}
