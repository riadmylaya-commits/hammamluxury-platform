<?php

namespace App\Filament\Admin\Resources\InvoiceResource\Pages;

use App\Domain\Billing\InvoiceIssuer;
use App\Filament\Admin\Resources\BookingResource;
use App\Filament\Admin\Resources\InvoiceResource;
use App\Models\Invoice;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    protected static string $view = 'filament.admin.resources.invoice-view';

    public function getTitle(): string
    {
        /** @var Invoice $i */
        $i = $this->record;

        return __('billing.invoice').' · '.$i->label().' · '.$i->partner->company_name;
    }

    protected function getHeaderActions(): array
    {
        return InvoiceResource::invoiceActions(Actions\Action::class);
    }

    /** @return list<string> */
    public function blockers(): array
    {
        /** @var Invoice $i */
        $i = $this->record;

        return $i->isDraft() ? app(InvoiceIssuer::class)->blockers($i) : [];
    }

    public function bookingUrl(int $id): string
    {
        return BookingResource::getUrl('view', ['record' => $id]);
    }
}
