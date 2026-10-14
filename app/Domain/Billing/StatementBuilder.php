<?php

namespace App\Domain\Billing;

use App\Models\Booking;
use App\Models\CommissionDispute;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\LedgerEntry;
use App\Models\Partner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Construit le relevé provisoire d'un partenaire (entité juridique) pour un mois donné à partir du grand livre.
 *
 * Règles : seules les écritures qualifiées « billable » sont retenues (jamais legacy ni non qualifiées) ; une écriture
 * n'entre dans le relevé d'un mois que si elle est acquise avant la clôture de ce mois (créée au plus tard le dernier jour) ;
 * la période d'origine est le mois de la prestation ; une écriture acquise après la clôture de son mois d'origine est reportée
 * au relevé du mois de son enregistrement avec mention de sa période d'origine (chaque écriture n'appartient qu'à un seul relevé) ; les réservations hors ligne, les arbitrages ouverts et les
 * réservations sous contestation ouverte sont retirés du relevé facturable (affichés à part). Aucune facture n'est créée.
 */
class StatementBuilder
{
    public function __construct(private readonly BillingSettings $settings, private readonly BillingGate $gate) {}

    /** Mois d'origine d'une écriture : mois de la prestation, sinon mois d'enregistrement. */
    public function originPeriod(LedgerEntry $entry): CarbonImmutable
    {
        $at = $entry->booking?->start_at ?? $entry->created_at;

        return CarbonImmutable::parse($at)->setTimezone(config('hl.timezone'))->startOfMonth();
    }

    /**
     * Mois de relevé d'une écriture : le premier mois, à partir de son mois d'origine, dont la clôture est postérieure à son
     * enregistrement (= max(mois d'origine, mois d'enregistrement)). Une écriture n'appartient ainsi qu'à un seul relevé.
     */
    public function billingPeriod(LedgerEntry $entry): CarbonImmutable
    {
        $created = $entry->created_at->toImmutable()->setTimezone(config('hl.timezone'))->startOfMonth();
        $origin = $this->originPeriod($entry);

        return $created->gt($origin) ? $created : $origin;
    }

    /** Mois (AAAA-MM) pour lesquels une facture est déjà émise. @return array<string, true> */
    public function issuedPeriods(Partner $partner): array
    {
        return Invoice::issued()->where('partner_id', $partner->id)->pluck('period')->flip()->map(fn () => true)->all();
    }

    /**
     * Mois de relevé effectif : le mois de relevé, ou, si la facture de ce mois (et des suivants) est déjà émise, le premier mois
     * suivant sans facture émise. Une écriture non facturée (contestation résolue tardivement, arbitrage) est ainsi reportée sans
     * perte, avec sa période d'origine conservée ; une écriture déjà facturée n'apparaît plus nulle part.
     *
     * @param  array<string, true>  $issuedPeriods
     */
    public function effectivePeriod(LedgerEntry $entry, array $issuedPeriods): CarbonImmutable
    {
        $period = $this->billingPeriod($entry);
        while (isset($issuedPeriods[$period->format('Y-m')])) {
            $period = $period->addMonth();
        }

        return $period;
    }

    public function build(Partner $partner, CarbonImmutable|string $period, ?CarbonImmutable $now = null): Statement
    {
        $tz = config('hl.timezone');
        $now ??= CarbonImmutable::now($tz);
        $period = ($period instanceof CarbonImmutable ? $period : CarbonImmutable::createFromFormat('Y-m-d', $period.'-01', $tz))->setTimezone($tz)->startOfMonth();
        $closeAt = $period->endOfMonth();
        $acquiredUntil = $now->lt($closeAt) ? $now : $closeAt;
        $disputeUntil = $period->addMonth()->day(max(1, min(28, (int) $this->settings->get('dispute_until_day'))))->endOfDay();

        $issued = $this->issuedPeriods($partner);
        $invoiced = InvoiceLine::query()->whereHas('invoice', fn ($q) => $q->issued()->where('partner_id', $partner->id))->pluck('ledger_entry_id')->flip()->all();
        $entries = LedgerEntry::query()
            ->where('partner_id', $partner->id)
            ->whereIn('type', LedgerEntry::COMMISSION_TYPES)
            ->where('billing_scope', LedgerEntry::SCOPE_BILLABLE)
            ->where('created_at', '<=', $acquiredUntil->utc())
            ->with(['booking.spa', 'booking.pendingCancellationRequest', 'spa'])
            ->orderBy('id')
            ->get()
            ->filter(fn (LedgerEntry $e) => $this->effectivePeriod($e, $issued)->eq($period))
            ->reject(fn (LedgerEntry $e) => isset($invoiced[$e->id]))
            ->filter(fn (LedgerEntry $e) => $e->booking === null || $e->booking->source !== 'partner');

        $blocking = CommissionDispute::blocking()->where('partner_id', $partner->id)
            ->whereIn('booking_id', $entries->pluck('booking_id')->filter()->unique()->all())
            ->get()->keyBy('booking_id');

        $spas = [];
        $excluded = [];
        $total = 0.0;
        $excludedTotal = 0.0;
        foreach ($entries->groupBy(fn (LedgerEntry $e) => $e->booking_id ?? 'entry-'.$e->id) as $group) {
            /** @var Collection<int, LedgerEntry> $group */
            $first = $group->first();
            $booking = $first->booking;
            $origin = $group->map(fn (LedgerEntry $e) => $this->originPeriod($e))->min();
            $line = [
                'booking_id' => $booking?->id,
                'reference' => $booking?->reference ?? $first->booking_reference,
                'spa_id' => $first->spa_id ?? $booking?->spa_id,
                'spa_name' => $first->spa?->name ?? $booking?->spa?->name,
                'service_at' => $booking?->start_at,
                'status' => $booking?->status,
                'base' => $booking?->amountDue(),
                'rate' => $booking?->commission_pct,
                'origin_period' => $origin->format('Y-m'),
                'carried' => $origin->lt($period),
                'entries' => $group->map(fn (LedgerEntry $e) => ['id' => $e->id, 'type' => $e->type, 'amount' => $e->amount, 'created_at' => $e->created_at, 'corrects' => $e->corrects_entry_id])->values()->all(),
                'amount' => round((float) $group->sum('amount'), 2),
                'entry_id' => $first->id,
                'exclusion' => null,
                'dispute_id' => null,
            ];
            if ($booking !== null && isset($blocking[$booking->id])) {
                $line['exclusion'] = 'disputed';
                $line['dispute_id'] = $blocking[$booking->id]->id;
            } elseif ($booking !== null && $booking->needsArbitration()) {
                $line['exclusion'] = 'arbitration';
            }
            if ($line['exclusion'] !== null) {
                $excluded[] = $line;
                $excludedTotal += $line['amount'];

                continue;
            }
            $spaId = (int) ($line['spa_id'] ?? 0);
            $spas[$spaId] ??= ['spa' => $first->spa ?? $booking?->spa, 'lines' => [], 'total' => 0.0];
            $spas[$spaId]['lines'][] = $line;
            $spas[$spaId]['total'] = round($spas[$spaId]['total'] + $line['amount'], 2);
            $total += $line['amount'];
        }
        foreach ($spas as &$section) {
            usort($section['lines'], fn ($a, $b) => ($a['service_at'] <=> $b['service_at']) ?: ($a['entry_id'] <=> $b['entry_id']));
        }
        unset($section);

        return new Statement($partner, $period, $acquiredUntil, $spas, $excluded, round($total, 2), round($excludedTotal, 2),
            $now->gt($closeAt), $disputeUntil, array_values(array_unique(array_merge($this->gate->globalBlockers(), $this->gate->partnerBlockers($partner)))));
    }

    /** Partenaires ayant au moins une écriture facturable acquise pour ce mois (candidats à la notification « relevé disponible »). */
    public function partnersWithBillableEntries(CarbonImmutable $period): Collection
    {
        return Partner::whereIn('id', LedgerEntry::query()->where('billing_scope', LedgerEntry::SCOPE_BILLABLE)
            ->whereIn('type', LedgerEntry::COMMISSION_TYPES)->where('created_at', '<=', $period->endOfMonth()->utc())->select('partner_id'))
            ->orderBy('id')->get();
    }

    /** Réservations d'un partenaire qui apparaissent dans le relevé facturable (hors exclusions) : support du formulaire de contestation. */
    public function billableBookingIds(Statement $statement): array
    {
        return array_values(array_filter(array_map(fn ($l) => $l['booking_id'], $statement->lines())));
    }

    public function bookingLine(Statement $statement, Booking $booking): ?array
    {
        foreach (array_merge($statement->lines(), $statement->excluded) as $line) {
            if ($line['booking_id'] === $booking->id) {
                return $line;
            }
        }

        return null;
    }
}
