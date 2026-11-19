<?php

namespace App\Filament\Shared;

use App\Domain\Billing\BillingSettings;
use App\Models\Booking;
use App\Models\InvoicePayment;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

/**
 * Suivi financier du mois, commun à l'Admin et à l'espace partenaire. HammamLuxury n'encaisse rien : les montants des
 * prestations sont ceux déclarés par l'établissement, et une commission n'est acquise qu'une fois la prestation
 * effectuée (ou des frais d'absence / d'annulation appliqués), jamais à la confirmation.
 */
class FinanceStats
{
    /**
     * @param  Builder<Booking>  $bookings  réservations du périmètre (un établissement, ou toute la plateforme)
     * @return array<string, float|int>
     */
    public static function month(Builder $bookings, ?int $partnerId = null): array
    {
        $range = [now()->startOfMonth(), now()->endOfMonth()];
        $month = (clone $bookings)->whereBetween('start_at', $range)->where('payment_status', '!=', 'refunded')
            ->whereIn('status', ['confirmed', 'completed', 'no_show', 'cancelled'])->get();
        $confirmed = $month->where('status', 'confirmed');
        $performed = $month->where('status', 'completed');
        $fees = $month->whereIn('status', ['no_show', 'cancelled']);

        return [
            'confirmed_count' => $confirmed->count(),
            'confirmed_total' => (float) $confirmed->sum('total'),
            'performed_count' => $performed->count(),
            'performed_total' => (float) $performed->sum(fn (Booking $b) => $b->amountDue()),
            'fees_total' => (float) $fees->sum(fn (Booking $b) => $b->amountDue()),
            'commission_forecast' => (float) $confirmed->sum(fn (Booking $b) => $b->source === 'partner' ? 0.0 : (float) $b->commission_amount),
            'commission_accrued' => (float) $month->sum(fn (Booking $b) => $b->commissionDue()),
            'commission_paid' => (float) InvoicePayment::query()->whereBetween('paid_on', [$range[0]->toDateString(), $range[1]->toDateString()])
                ->where('method', '!=', InvoicePayment::METHOD_CREDIT)
                ->when($partnerId, fn (Builder $q) => $q->where('partner_id', $partnerId))->sum('amount'),
        ];
    }

    /**
     * @param  array{confirmed?: string, accrued?: string}  $urls
     * @return list<Stat>
     */
    public static function stats(Builder $bookings, ?int $partnerId = null, array $urls = []): array
    {
        $link = ['class' => 'cursor-pointer transition hover:ring-2 hover:ring-primary-500'];
        $m = self::month($bookings, $partnerId);
        $money = fn (float $v) => number_format($v, fmod(round($v, 2), 1.0) === 0.0 ? 0 : 2, ',', ' ').' '.config('hl.currency');

        return [
            Stat::make(__('booking.finance_confirmed'), $money($m['confirmed_total']))
                ->description(trans_choice('booking.finance_confirmed_help', $m['confirmed_count'], ['count' => $m['confirmed_count']]))
                ->url($urls['confirmed'] ?? null)->extraAttributes(isset($urls['confirmed']) ? $link : []),
            Stat::make(__('booking.finance_performed'), $money($m['performed_total']))
                ->description(trans_choice('booking.finance_performed_help', $m['performed_count'], ['count' => $m['performed_count']])
                    .($m['fees_total'] > 0 ? ' '.__('booking.finance_fees_applied', ['amount' => $money($m['fees_total'])]) : '')),
            Stat::make(__('booking.finance_commission_forecast'), $money($m['commission_forecast']))->color('gray')
                ->description(__('booking.finance_commission_forecast_help')),
            Stat::make(__('booking.finance_commission_accrued'), $money($m['commission_accrued']))->color('success')
                ->description(__('booking.finance_commission_accrued_help'))
                ->url($urls['accrued'] ?? null)->extraAttributes(isset($urls['accrued']) ? $link : []),
            Stat::make(__('booking.finance_commission_paid'), $money($m['commission_paid']))
                ->description(__(app(BillingSettings::class)->enabled() ? 'booking.finance_commission_paid_help' : 'booking.finance_commission_paid_off')),
        ];
    }
}
