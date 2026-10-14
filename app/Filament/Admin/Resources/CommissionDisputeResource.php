<?php

namespace App\Filament\Admin\Resources;

use App\Domain\Billing\DisputeException;
use App\Domain\Billing\DisputeService;
use App\Filament\Admin\Resources\CommissionDisputeResource\Pages;
use App\Filament\Shared\DisputeDisplay;
use App\Models\CommissionDispute;
use App\Models\Partner;
use App\Models\Spa;
use Filament\Actions;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Contestations de commission : décision motivée de HammamLuxury, sans modification du grand livre. */
class CommissionDisputeResource extends Resource
{
    protected static ?string $model = CommissionDispute::class;

    protected static ?string $navigationIcon = 'heroicon-o-flag';

    protected static ?int $navigationSort = 62;

    public static function getNavigationGroup(): ?string
    {
        return __('billing.nav_group');
    }

    public static function getModelLabel(): string
    {
        return __('billing.dispute');
    }

    public static function getPluralModelLabel(): string
    {
        return __('billing.disputes');
    }

    public static function getNavigationBadge(): ?string
    {
        $n = CommissionDispute::open()->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['partner', 'spa', 'booking', 'ledgerEntry']);
    }

    /** @return array<Tables\Actions\Action|Actions\Action> */
    public static function decisionActions(string $class = Tables\Actions\Action::class): array
    {
        $decide = function (CommissionDispute $d, string $decision, string $note, mixed $livewire): void {
            try {
                app(DisputeService::class)->decide($d, auth()->user(), $decision, $note);
                $d->refresh();
                Notification::make()->title(__('partner.action_done'))->success()->send();
                if ($livewire instanceof ViewRecord) {
                    $livewire->redirect(static::getUrl('view', ['record' => $d]));
                }
            } catch (DisputeException $e) {
                Notification::make()->title(__('partner.action_failed'))->body($e->getMessage())->danger()->send();
            }
        };
        $note = Textarea::make('decision_note')->label(__('billing.decision_note'))->required()->rows(3)->maxLength(2000);

        return [
            $class::make('accept')->label(__('billing.accept_dispute'))->icon('heroicon-o-check')->color('success')
                ->visible(fn (CommissionDispute $d) => $d->isOpen())
                ->form([$note])->requiresConfirmation()->modalDescription(__('billing.accept_dispute_help'))
                ->action(fn (CommissionDispute $d, array $data, mixed $livewire) => $decide($d, 'accepted', $data['decision_note'], $livewire)),
            $class::make('reject')->label(__('billing.reject_dispute'))->icon('heroicon-o-x-mark')->color('danger')
                ->visible(fn (CommissionDispute $d) => $d->isOpen())
                ->form([$note])->requiresConfirmation()->modalDescription(__('billing.reject_dispute_help'))
                ->action(fn (CommissionDispute $d, array $data, mixed $livewire) => $decide($d, 'rejected', $data['decision_note'], $livewire)),
            $class::make('booking')->label(__('billing.open_booking'))->icon('heroicon-o-calendar-days')->color('gray')
                ->url(fn (CommissionDispute $d) => BookingResource::getUrl('view', ['record' => $d->booking_id])),
            DisputeDisplay::evidenceAction($class),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')
            ->columns(DisputeDisplay::columns(withPartner: true))
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label(__('partner.status'))->default('open')
                    ->options(array_combine(CommissionDispute::STATUSES, array_map(fn ($s) => __('billing.dispute_status_'.$s), CommissionDispute::STATUSES))),
                Tables\Filters\SelectFilter::make('kind')->label(__('billing.kind'))
                    ->options(array_combine(CommissionDispute::KINDS, array_map(fn ($k) => __('billing.kind_'.$k), CommissionDispute::KINDS))),
                Tables\Filters\SelectFilter::make('partner_id')->label(__('billing.partner'))->options(fn () => Partner::orderBy('company_name')->pluck('company_name', 'id')->all())->searchable(),
                Tables\Filters\SelectFilter::make('spa_id')->label(__('billing.spa'))->options(fn () => Spa::orderBy('name')->pluck('name', 'id')->all())->searchable(),
                Tables\Filters\SelectFilter::make('period')->label(__('billing.origin_period'))->options(fn () => CommissionDispute::query()->distinct()->orderByDesc('period')->pluck('period', 'period')->all()),
            ])
            ->actions([Tables\Actions\ViewAction::make(), ...self::decisionActions()])
            ->emptyStateHeading(__('billing.disputes_empty'));
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema(DisputeDisplay::infolist());
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCommissionDisputes::route('/'),
            'view' => Pages\ViewCommissionDispute::route('/{record}'),
        ];
    }
}
