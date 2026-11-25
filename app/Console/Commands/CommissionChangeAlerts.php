<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Partner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class CommissionChangeAlerts extends Command
{
    protected $signature = 'hl:commission-alerts {--days=7}';

    protected $description = 'Alerte l\'administration avant chaque changement automatique de taux de commission (une fois par changement)';

    public function handle(): int
    {
        $to = (string) config('hl.alert_email');
        if ($to === '') {
            $this->warn('hl.alert_email non configuré : aucune alerte.');

            return self::SUCCESS;
        }
        $today = CarbonImmutable::now(config('hl.timezone'))->startOfDay();
        $limit = $today->addDays((int) $this->option('days'));
        $sent = 0;
        foreach (Partner::has('commissionPeriods')->with(['commissionPeriods', 'spas'])->get() as $partner) {
            if (! $partner->isCommercial()) {
                continue; // fiches référencées : 0 % quoi qu'il arrive, aucun changement réel à annoncer
            }
            $byDay = [];
            foreach ($partner->commissionPeriods as $p) {
                foreach ([$p->starts_on, $p->ends_on?->addDay()] as $change) {
                    if ($change !== null && $change->gt($today) && $change->lte($limit)) {
                        $byDay[$change->format('Y-m-d')] = true;
                    }
                }
            }
            ksort($byDay);
            foreach (array_keys($byDay) as $day) {
                $at = CarbonImmutable::parse($day, config('hl.timezone'));
                $before = $partner->commissionPct($at->subDay());
                $after = $partner->commissionPct($at);
                $done = ActivityLog::where('action', 'partner.commission_change_alerted')->where('subject_type', $partner->getMorphClass())->where('subject_id', $partner->id)->get()
                    ->contains(fn (ActivityLog $l) => ($l->properties['on'] ?? null) === $day && (float) ($l->properties['from'] ?? -1) === $before && (float) ($l->properties['to'] ?? -1) === $after);
                if ($before === $after || $done) {
                    continue;
                }
                $body = "Partenaire : {$partner->company_name}\nChangement automatique le {$at->format('d/m/Y')} : commission de {$before} % à {$after} %.\n"
                    ."Les réservations déjà enregistrées gardent leur taux. Pour modifier ces conditions : Administration > Partenaires > {$partner->company_name} > Périodes commerciales.";
                Mail::raw($body, fn ($m) => $m->to($to)->subject("HammamLuxury - commission de {$partner->company_name} : {$before} % → {$after} % le {$at->format('d/m/Y')}"));
                ActivityLog::record('partner.commission_change_alerted', $partner, ['on' => $day, 'from' => $before, 'to' => $after], 'system');
                $sent++;
            }
        }
        $this->info("Alertes envoyées : {$sent}");

        return self::SUCCESS;
    }
}
