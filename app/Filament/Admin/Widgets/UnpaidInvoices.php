<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Pages\OverpaymentsPage;
use App\Filament\Admin\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Models\InvoiceOverpayment;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Impayés : factures émises non soldées, solde total dû, retards, trop-perçus à régulariser. */
class UnpaidInvoices extends BaseWidget
{
    protected static ?int $sort = 2;

    public static function canView(): bool
    {
        return Invoice::issued()->exists();
    }

    protected function getStats(): array
    {
        $open = Invoice::openForPayment();
        $fmt = fn ($v) => number_format((float) $v, 2, ',', ' ').' '.config('hl.currency');
        $overdue = (clone $open)->where('status', Invoice::STATUS_OVERDUE)->count();
        $overpayments = InvoiceOverpayment::open();

        return [
            Stat::make(__('billing.unpaid_widget'), (clone $open)->count())->description(__('billing.unpaid_total').' : '.$fmt((clone $open)->sum('balance')))
                ->color((clone $open)->exists() ? 'warning' : 'success')->url(InvoiceResource::getUrl('index', ['activeTab' => 'issued'])),
            Stat::make(__('billing.overdue_count'), $overdue)->description($fmt((clone $open)->where('status', Invoice::STATUS_OVERDUE)->sum('balance')))
                ->color($overdue > 0 ? 'danger' : 'success')->url(InvoiceResource::getUrl('index', ['activeTab' => 'issued', 'tableFilters' => ['overdue' => ['isActive' => true]]])),
            Stat::make(__('billing.overpayments'), (clone $overpayments)->count())->description(__('billing.overpayment_status_open').' : '.$fmt((clone $overpayments)->sum('amount')))
                ->color((clone $overpayments)->exists() ? 'warning' : 'gray')->url(OverpaymentsPage::getUrl()),
        ];
    }
}
