<?php

namespace App\Filament\Admin\Pages;

use App\Domain\Billing\BillingSettings;
use App\Domain\Billing\CreditNoteService;
use App\Filament\Admin\Resources\BookingResource;
use App\Filament\Admin\Resources\InvoiceResource;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/** File des corrections post-émission : écritures négatives à régulariser par avoir sur la facture d'origine (création depuis la facture). */
class PostIssueCorrectionsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-receipt-refund';

    protected static ?int $navigationSort = 63;

    protected static string $view = 'filament.admin.pages.post-issue-corrections';

    public static function getNavigationGroup(): ?string
    {
        return __('billing.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('billing.corrections_pending');
    }

    public static function getNavigationBadge(): ?string
    {
        $n = app(CreditNoteService::class)->pendingCorrections()->count();

        return $n > 0 ? (string) $n : null;
    }

    public function getTitle(): string
    {
        return __('billing.corrections_pending');
    }

    public function getSubheading(): ?string
    {
        return __('billing.corrections_pending_help');
    }

    /** @return Collection<int, array<string, mixed>> */
    public function corrections(): Collection
    {
        return app(CreditNoteService::class)->pendingCorrections()->sortByDesc('age_days')->values();
    }

    public function dueDays(): int
    {
        return (int) app(BillingSettings::class)->get('correction_due_days');
    }

    public function invoiceUrl(int $id): string
    {
        return InvoiceResource::getUrl('view', ['record' => $id]);
    }

    public function bookingUrl(int $id): string
    {
        return BookingResource::getUrl('view', ['record' => $id]);
    }
}
