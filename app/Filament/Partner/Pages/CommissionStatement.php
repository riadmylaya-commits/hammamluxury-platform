<?php

namespace App\Filament\Partner\Pages;

use App\Domain\Billing\DisputeException;
use App\Domain\Billing\DisputeService;
use App\Domain\Billing\Statement;
use App\Domain\Billing\StatementBuilder;
use App\Filament\Partner\Resources\BookingResource;
use App\Filament\Partner\Resources\CommissionDisputeResource;
use App\Filament\Shared\BillingPeriods;
use App\Filament\Shared\BookingActions;
use App\Models\Booking;
use App\Models\CommissionDispute;
use App\Models\Partner;
use App\Models\Spa;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/** Relevé provisoire des commissions de l'entité juridique (tous ses établissements), avec contestation par réservation. */
class CommissionStatement extends Page implements HasActions, HasForms
{
    use InteractsWithActions, InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?int $navigationSort = 50;

    protected static string $view = 'filament.partner.pages.commission-statement';

    public string $period = '';

    public static function getNavigationGroup(): ?string
    {
        return __('billing.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('billing.statement_nav');
    }

    public function getTitle(): string
    {
        return __('billing.statement_title');
    }

    public function getSubheading(): ?string
    {
        return __('billing.statement_help');
    }

    /** Mois demandé dans l'URL (`?period=AAAA-MM`, ex. depuis le tableau de bord), sinon le mois précédent. */
    public function mount(): void
    {
        $asked = request()->query('period');
        $this->period = BillingPeriods::valid($asked) && array_key_exists($asked, BillingPeriods::options()) ? $asked : BillingPeriods::previous();
    }

    /** Le partenaire est toujours résolu depuis le tenant courant, jamais depuis la requête. */
    private function partner(): Partner
    {
        /** @var Spa $spa */
        $spa = Filament::getTenant();

        return $spa->partner;
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
        return BillingPeriods::options();
    }

    public function statement(): Statement
    {
        return app(StatementBuilder::class)->build($this->partner(), BillingPeriods::valid($this->period) ? $this->period : BillingPeriods::previous());
    }

    public function openDisputesCount(): int
    {
        return CommissionDispute::open()->where('partner_id', $this->partner()->id)->count();
    }

    public function disputesUrl(): string
    {
        return CommissionDisputeResource::getUrl('index');
    }

    public function bookingUrl(int $id): string
    {
        return BookingResource::getUrl('view', ['record' => $id]);
    }

    public function disputeAction(): Action
    {
        return Action::make('dispute')
            ->label(__('billing.dispute_action'))
            ->modalHeading(fn (array $arguments) => __('billing.dispute_heading', ['ref' => $this->bookingFor($arguments)?->reference ?? '']))
            ->modalDescription(fn () => app(DisputeService::class)->kindFor($this->statement()) === 'dispute' ? __('billing.dispute_help') : __('billing.correction_help'))
            ->form([
                Forms\Components\Select::make('reason_code')->label(__('billing.reason'))->required()
                    ->options(array_combine(CommissionDispute::REASONS, array_map(fn ($r) => __('billing.reason_'.$r), CommissionDispute::REASONS))),
                Forms\Components\Textarea::make('comment')->label(__('billing.comment'))->required()->rows(4)->maxLength(2000),
                BookingActions::evidenceUpload(__('billing.evidence'))->directory('disputes'),
            ])
            ->action(function (array $arguments, array $data) {
                $booking = $this->bookingFor($arguments);
                if ($booking === null) {
                    Notification::make()->title(__('partner.action_failed'))->danger()->send();

                    return;
                }
                try {
                    $dispute = app(DisputeService::class)->open($this->partner(), $booking, auth()->user(), $data['reason_code'], $data['comment'], $data['evidence'] ?? null);
                    Notification::make()->title(__('billing.dispute_'.$dispute->kind.'_created'))->success()->send();
                } catch (DisputeException $e) {
                    Notification::make()->title(__('partner.action_failed'))->body($e->getMessage())->danger()->send();
                }
            });
    }

    /** Réservation d'un établissement du partenaire courant uniquement ; toute autre valeur est ignorée. */
    private function bookingFor(array $arguments): ?Booking
    {
        $id = (int) ($arguments['booking'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        return Booking::whereKey($id)->whereIn('spa_id', $this->partner()->spas()->select('id'))->first();
    }
}
