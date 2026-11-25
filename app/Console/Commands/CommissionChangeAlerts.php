<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Partner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class CommissionChangeAlerts extends Command
{
    private const FIRST_ALERT_DAYS = 7;

    protected $signature = 'hl:commission-alerts';

    protected $description = 'Alerte l\'administration avant chaque changement automatique de taux de commission (J-7 et J-1, sans doublon)';

    public function handle(): int
    {
        $to = (string) config('hl.alert_email');
        if ($to === '') {
            $this->warn('hl.alert_email non configuré : aucune alerte.');

            return self::SUCCESS;
        }
        $sent = 0;
        foreach (Partner::has('commissionPeriods')->with(['commissionPeriods', 'spas'])->get() as $partner) {
            if (! $partner->isCommercial()) {
                continue; // fiches référencées : 0 % quoi qu'il arrive, aucun changement réel à annoncer
            }
            $logs = ActivityLog::where('action', 'partner.commission_change_alerted')->where('subject_type', $partner->getMorphClass())->where('subject_id', $partner->id)->get();
            foreach ($partner->commissionChanges() as $c) {
                if ($c['days'] > self::FIRST_ALERT_DAYS) {
                    break;
                }
                // Deux rappels par changement : J-7 (dès qu'il entre dans la semaine) et J-1 (la veille). Jamais deux fois le même.
                $stage = $c['days'] <= 1 ? 'J-1' : 'J-7';
                $key = ['on' => $c['on']->format('Y-m-d'), 'from' => $c['from'], 'to' => $c['to'], 'stage' => $stage];
                if ($logs->contains(fn (ActivityLog $l) => ($l->properties['on'] ?? null) === $key['on'] && ($l->properties['stage'] ?? 'J-7') === $stage
                    && (float) ($l->properties['from'] ?? -1) === $c['from'] && (float) ($l->properties['to'] ?? -1) === $c['to'])) {
                    continue;
                }
                $date = $c['on']->format('d/m/Y');
                $when = $c['days'] <= 1 ? 'demain' : "dans {$c['days']} jours";
                $body = "Partenaire : {$partner->company_name}\nChangement automatique {$when}, le {$date} : commission de {$c['from']} % à {$c['to']} %.\n"
                    ."Les réservations déjà enregistrées gardent leur taux. Pour modifier ou prolonger ces conditions : Administration > Partenaires > {$partner->company_name} > Périodes commerciales.";
                Mail::raw($body, fn ($m) => $m->to($to)->subject("HammamLuxury - {$stage} - commission de {$partner->company_name} : {$c['from']} % → {$c['to']} % le {$date}"));
                ActivityLog::record('partner.commission_change_alerted', $partner, $key, 'system');
                $sent++;
            }
        }
        $this->info("Alertes envoyées : {$sent}");

        return self::SUCCESS;
    }
}
