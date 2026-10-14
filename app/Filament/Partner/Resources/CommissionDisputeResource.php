<?php

namespace App\Filament\Partner\Resources;

use App\Filament\Partner\Resources\CommissionDisputeResource\Pages;
use App\Filament\Shared\DisputeDisplay;
use App\Models\CommissionDispute;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Contestations de l'entité juridique du partenaire courant (lecture seule ; création depuis le relevé). */
class CommissionDisputeResource extends Resource
{
    protected static ?string $model = CommissionDispute::class;

    protected static ?string $navigationIcon = 'heroicon-o-flag';

    protected static ?int $navigationSort = 55;

    protected static bool $isScopedToTenant = false;

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

    public static function canCreate(): bool
    {
        return false;
    }

    /** Toujours filtré par l'entité juridique du tenant courant. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('partner_id', Filament::getTenant()?->partner_id ?? 0)->with(['spa', 'booking', 'ledgerEntry']);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')
            ->columns(DisputeDisplay::columns())
            ->filters([Tables\Filters\SelectFilter::make('status')->label(__('partner.status'))->options(array_combine(CommissionDispute::STATUSES, array_map(fn ($s) => __('billing.dispute_status_'.$s), CommissionDispute::STATUSES)))])
            ->actions([Tables\Actions\ViewAction::make()])
            ->emptyStateHeading(__('billing.disputes_empty'));
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema(DisputeDisplay::infolist());
    }

    /** @return array<Actions\Action> */
    public static function viewActions(): array
    {
        return [DisputeDisplay::evidenceAction(Actions\Action::class)];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCommissionDisputes::route('/'),
            'view' => Pages\ViewCommissionDispute::route('/{record}'),
        ];
    }
}
