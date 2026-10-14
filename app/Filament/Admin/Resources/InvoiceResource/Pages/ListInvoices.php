<?php

namespace App\Filament\Admin\Resources\InvoiceResource\Pages;

use App\Filament\Admin\Resources\InvoiceResource;
use App\Models\Invoice;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('billing.all')),
            'draft' => Tab::make(__('billing.drafts'))->modifyQueryUsing(fn (Builder $q) => $q->draft()),
            'issued' => Tab::make(__('billing.to_pay'))->modifyQueryUsing(fn (Builder $q) => $q->issued()->whereNotIn('status', ['paid', 'cancelled'])),
            'paid' => Tab::make(__('billing.invoice_status_paid'))->modifyQueryUsing(fn (Builder $q) => $q->where('status', 'paid')),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [];
    }

    public function getSubheading(): ?string
    {
        $n = Invoice::draft()->count();

        return $n ? __('billing.drafts').' : '.$n : null;
    }
}
