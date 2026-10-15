<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Resources\InvoiceResource;
use App\Models\InvoiceOverpayment;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;

/** Trop-perçus signalés : lecture seule en F3 (la régularisation — imputation ou remboursement — relève du lot avoirs). */
class OverpaymentsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?int $navigationSort = 64;

    protected static string $view = 'filament.admin.pages.overpayments';

    public static function getNavigationGroup(): ?string
    {
        return __('billing.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('billing.overpayments');
    }

    public static function getNavigationBadge(): ?string
    {
        $n = InvoiceOverpayment::open()->count();

        return $n > 0 ? (string) $n : null;
    }

    public function getTitle(): string
    {
        return __('billing.overpayments');
    }

    public function getSubheading(): ?string
    {
        return __('billing.overpayment_help');
    }

    public function overpayments(): Collection
    {
        return InvoiceOverpayment::with(['invoice', 'partner', 'payment', 'decidedBy'])->orderByRaw("status = 'open' DESC")->orderByDesc('id')->get();
    }

    public function invoiceUrl(int $id): string
    {
        return InvoiceResource::getUrl('view', ['record' => $id]);
    }
}
