<?php

namespace App\Filament\Partner\Pages;

use App\Filament\Admin\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\Spa;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;

/** Factures émises de l'entité juridique du tenant courant (tous ses établissements) ; jamais les brouillons. */
class Invoices extends Page implements HasActions, HasForms
{
    use InteractsWithActions, InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?int $navigationSort = 52;

    protected static string $view = 'filament.partner.pages.invoices';

    public ?int $selected = null;

    public static function getNavigationGroup(): ?string
    {
        return __('billing.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('billing.invoices');
    }

    public function getTitle(): string
    {
        return __('billing.invoices');
    }

    public function getSubheading(): ?string
    {
        return __('billing.invoices_help');
    }

    /** Le partenaire est toujours résolu depuis le tenant courant, jamais depuis la requête. */
    private function partner(): Partner
    {
        /** @var Spa $spa */
        $spa = Filament::getTenant();

        return $spa->partner;
    }

    /** @return Collection<int, Invoice> */
    public function invoices(): Collection
    {
        return Invoice::issued()->where('partner_id', $this->partner()->id)->orderByDesc('issued_at')->get();
    }

    /** Facture sélectionnée, toujours filtrée par l'entité du tenant. */
    public function invoice(): ?Invoice
    {
        return $this->selected ? Invoice::issued()->where('partner_id', $this->partner()->id)->with('lines.spa')->find($this->selected) : null;
    }

    public function select(int $id): void
    {
        $this->selected = $this->selected === $id ? null : $id;
    }

    public function pdfAction(): Action
    {
        return InvoiceResource::pdfAction(Action::class)
            ->record(fn () => $this->invoice())
            ->visible(fn () => ($i = $this->invoice()) !== null && $i->pdf_status === Invoice::PDF_READY);
    }
}
