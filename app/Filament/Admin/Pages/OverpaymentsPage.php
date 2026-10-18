<?php

namespace App\Filament\Admin\Pages;

use App\Domain\Billing\CreditNoteException;
use App\Domain\Billing\PartnerCreditService;
use App\Filament\Admin\Resources\InvoiceResource;
use App\Models\InvoiceOverpayment;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;

/** Trop-perçus signalés (F3) ; depuis F4, l'Admin peut ouvrir un crédit partenaire à imputer ou rembourser manuellement (page Crédits). */
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

    public function creditsUrl(): string
    {
        return PartnerCreditsPage::getUrl();
    }

    public function openCreditAction(): Action
    {
        return Action::make('openCredit')->label(__('billing.credit_open'))->icon('heroicon-o-wallet')->color('warning')->size('xs')
            ->requiresConfirmation()->modalDescription(__('billing.credit_open_help'))
            ->action(function (array $arguments) {
                $o = InvoiceOverpayment::find((int) ($arguments['overpayment'] ?? 0));
                if ($o === null) {
                    return;
                }
                try {
                    app(PartnerCreditService::class)->fromOverpayment($o, auth()->user());
                    Notification::make()->title(__('billing.credit_opened'))->success()->send();
                    $this->redirect(PartnerCreditsPage::getUrl());
                } catch (CreditNoteException $e) {
                    Notification::make()->title(__('billing.credit_refused'))->body($e->getMessage())->danger()->persistent()->send();
                }
            });
    }
}
