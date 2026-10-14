<?php

namespace App\Console\Commands;

use App\Domain\Billing\BillingNotifier;
use App\Listeners\SendBookingNotifications;
use App\Models\BillingNotification;
use App\Models\BookingNotification;
use Illuminate\Console\Command;

class RetryBookingNotifications extends Command
{
    protected $signature = 'hl:notifications-retry {--max=5 : Nombre maximal de tentatives par destinataire} {--alert : Alerte e-mail si des échecs définitifs subsistent}';

    protected $description = 'Remet en file les notifications de réservation et de facturation échouées ou perdues (par destinataire, sans doublon)';

    public function handle(): int
    {
        $max = (int) $this->option('max');
        $retried = SendBookingNotifications::retry($max) + app(BillingNotifier::class)->retry($max);
        $exhausted = BookingNotification::where('status', 'failed')->where('attempts', '>=', $max)->count()
            + BillingNotification::where('status', 'failed')->where('attempts', '>=', $max)->count();
        $this->info("Relancées : {$retried} ; échecs définitifs : {$exhausted}");

        if ($exhausted > 0 && $this->option('alert')) {
            $this->call('hl:alert', ['subject' => "[HL] {$exhausted} notification(s) de réservation en échec définitif", 'body' => 'Voir booking_notifications et billing_notifications (status=failed).']);
        }

        return $exhausted > 0 ? self::FAILURE : self::SUCCESS;
    }
}
