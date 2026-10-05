<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\BookingDeclineResource\Pages;
use App\Models\BookingDecline;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Refus de demandes par les établissements : qui, quelle réservation, quand, quel motif, explication,
 * et constat du moteur de capacité pour repérer les refus « Plus de place » alors que la place existait.
 */
class BookingDeclineResource extends Resource
{
    protected static ?string $model = BookingDecline::class;

    protected static ?string $navigationIcon = 'heroicon-o-hand-raised';

    protected static ?int $navigationSort = 34;

    public static function getModelLabel(): string
    {
        return __('admin.decline');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.declines');
    }

    public static function getNavigationBadge(): ?string
    {
        $n = BookingDecline::where('reason', 'full')->where('engine_available', true)->where('created_at', '>=', now()->subDays(30))->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function engineLabel(?bool $state): string
    {
        return $state === null ? __('admin.decline_engine_unknown') : ($state ? __('admin.decline_engine_yes') : __('admin.decline_engine_no'));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['booking', 'spa', 'user']))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label(__('partner.decided_at'))->dateTime('d/m/Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('spa.name')->label(__('admin.spa'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('booking.reference')->label(__('partner.reference'))->searchable()->weight('bold')
                    ->url(fn (BookingDecline $d) => BookingResource::getUrl('view', ['record' => $d->booking_id])),
                Tables\Columns\TextColumn::make('start_at')->label(__('partner.date_time'))->dateTime('d/m/Y H:i')->sortable()
                    ->description(fn (BookingDecline $d) => $d->party.' '.__('partner.pers')),
                Tables\Columns\TextColumn::make('reason')->label(__('partner.decline_reason'))->badge()
                    ->formatStateUsing(fn ($state) => __('partner.decline_reasons')[$state] ?? $state)
                    ->color(fn ($state) => $state === 'full' ? 'warning' : 'gray'),
                Tables\Columns\TextColumn::make('engine_available')->label(__('admin.decline_engine'))->badge()
                    ->formatStateUsing(fn ($state) => static::engineLabel($state === null ? null : (bool) $state))
                    ->color(fn (BookingDecline $d) => $d->isSuspicious() ? 'danger' : 'gray')
                    ->icon(fn (BookingDecline $d) => $d->isSuspicious() ? 'heroicon-o-exclamation-triangle' : null)
                    ->tooltip(__('admin.decline_engine_help')),
                Tables\Columns\TextColumn::make('user.name')->label(__('admin.decline_actor'))
                    ->getStateUsing(fn (BookingDecline $d) => $d->user?->name ?? (__('admin.decline_actors')[$d->actor] ?? $d->actor)),
                Tables\Columns\TextColumn::make('note')->label(__('partner.decline_note'))->limit(40)->placeholder('—')->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('reason')->label(__('partner.decline_reason'))->options(__('partner.decline_reasons')),
                Tables\Filters\SelectFilter::make('spa_id')->label(__('admin.spa'))->relationship('spa', 'name')->searchable()->preload(),
                Tables\Filters\Filter::make('suspicious')->label(__('admin.decline_suspicious_only'))
                    ->query(fn (Builder $q) => $q->where('reason', 'full')->where('engine_available', true)),
            ])
            ->actions([Tables\Actions\ViewAction::make()])
            ->emptyStateHeading(__('admin.no_declines'));
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make(__('admin.decline'))->schema([
                TextEntry::make('spa.name')->label(__('admin.spa')),
                TextEntry::make('booking.reference')->label(__('partner.reference'))->weight('bold')
                    ->url(fn (BookingDecline $d) => BookingResource::getUrl('view', ['record' => $d->booking_id])),
                TextEntry::make('start_at')->label(__('partner.date_time'))->dateTime('l d F Y · H:i'),
                TextEntry::make('party')->label(__('admin.decline_party')),
                TextEntry::make('reason')->label(__('partner.decline_reason'))->badge()
                    ->formatStateUsing(fn ($state) => __('partner.decline_reasons')[$state] ?? $state)
                    ->color(fn ($state) => $state === 'full' ? 'warning' : 'gray'),
                TextEntry::make('engine_available')->label(__('admin.decline_engine'))->badge()
                    ->formatStateUsing(fn ($state) => static::engineLabel($state === null ? null : (bool) $state))
                    ->color(fn (BookingDecline $d) => $d->isSuspicious() ? 'danger' : 'gray')->helperText(__('admin.decline_engine_help')),
                TextEntry::make('user.name')->label(__('partner.declined_by'))
                    ->getStateUsing(fn (BookingDecline $d) => $d->user?->name ?? (__('admin.decline_actors')[$d->actor] ?? $d->actor)),
                TextEntry::make('created_at')->label(__('partner.decided_at'))->dateTime('d/m/Y H:i'),
                TextEntry::make('note')->label(__('partner.decline_note'))->placeholder('—')->columnSpanFull(),
                TextEntry::make('stats')->label('')->columnSpanFull()->getStateUsing(function (BookingDecline $d) {
                    $q = BookingDecline::where('spa_id', $d->spa_id)->where('reason', 'full')->where('created_at', '>=', now()->subDays(90));

                    return __('admin.decline_recent_stats', ['n' => $q->count(), 's' => $q->clone()->where('engine_available', true)->count()]);
                }),
            ])->columns(4),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBookingDeclines::route('/'),
            'view' => Pages\ViewBookingDecline::route('/{record}'),
        ];
    }
}
