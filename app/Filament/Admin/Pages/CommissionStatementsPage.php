<?php

namespace App\Filament\Admin\Pages;

use App\Domain\Billing\Statement;
use App\Domain\Billing\StatementBuilder;
use App\Filament\Admin\Resources\BookingResource;
use App\Filament\Shared\BillingPeriods;
use App\Models\Partner;
use Filament\Pages\Page;

/** Relevés provisoires par partenaire et période : vérification des commissions avant toute émission (lecture seule). */
class CommissionStatementsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?int $navigationSort = 61;

    protected static string $view = 'filament.admin.pages.commission-statements';

    public string $period = '';

    public ?int $partnerId = null;

    public static function getNavigationGroup(): ?string
    {
        return __('billing.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('billing.statements_nav');
    }

    public function getTitle(): string
    {
        return __('billing.statements_title');
    }

    public function getSubheading(): ?string
    {
        return __('billing.statements_help');
    }

    public function mount(): void
    {
        $this->period = BillingPeriods::previous();
    }

    public function updatedPeriod(): void
    {
        if (! BillingPeriods::valid($this->period)) {
            $this->period = BillingPeriods::previous();
        }
    }

    /** @return array<string, string> */
    public function periodOptions(): array
    {
        return BillingPeriods::options(24);
    }

    /** @return array<int, string> */
    public function partnerOptions(): array
    {
        return Partner::orderBy('company_name')->pluck('company_name', 'id')->all();
    }

    public function statement(): ?Statement
    {
        $partner = $this->partnerId ? Partner::find($this->partnerId) : null;
        if ($partner === null) {
            return null;
        }

        return app(StatementBuilder::class)->build($partner, BillingPeriods::valid($this->period) ? $this->period : BillingPeriods::previous());
    }

    public function bookingUrl(int $id): string
    {
        return BookingResource::getUrl('view', ['record' => $id]);
    }
}
