<?php

namespace App\Console\Commands;

use App\Listeners\SendBookingNotifications;
use App\Models\BookingNotification;
use Illuminate\Console\Command;

class RetryBookingNotifications extends Command
{
    protected $signature = 'hl:notifications-retry {--max=5 : Nombre maximal de tentatives par destinataire} {--alert : Alerte e-mail si des échecs définitifs subsistent}';

    protected $description = 'Remet en file les notifications de réservation échouées ou perdues (par destinataire, sans doublon)';

    public function handle(): int
    {
        $retried = SendBookingNotifications::retry((int) $this->option('max'));
        $exhausted = BookingNotification::where('status', 'failed')->where('attempts', '>=', (int) $this->option('max'))->count();
        $this->info("Relancées : {$retried} ; échecs définitifs : {$exhausted}");

        if ($exhausted > 0 && $this->option('alert')) {
            $this->call('hl:alert', ['subject' => "[HL] {$exhausted} notification(s) de réservation en échec définitif", 'body' => 'Voir booking_notifications (status=failed).']);
        }

        return $exhausted > 0 ? self::FAILURE : self::SUCCESS;
    }
}
