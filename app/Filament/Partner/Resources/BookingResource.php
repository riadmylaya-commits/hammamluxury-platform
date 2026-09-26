<?php

namespace App\Filament\Partner\Resources;

use App\Filament\Partner\Resources\BookingResource\Pages;
use App\Filament\Shared\BookingActions;
use App\Models\Booking;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BookingResource extends Resource
{
    protected static ?string $model = Booking::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?int $navigationSort = 10;

    public static function getModelLabel(): string
    {
        return __('partner.booking');
    }

    public static function getPluralModelLabel(): string
    {
        return __('partner.bookings');
    }

    public static function getNavigationBadge(): ?string
    {
        $n = static::getEloquentQuery()->where('status', 'waiting')->count();

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

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('start_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('participants'))
            ->columns([
                Tables\Columns\TextColumn::make('start_at')->label(__('partner.date_time'))->dateTime('D d/m · H:i')->sortable()
                    ->description(fn (Booking $b) => '→ '.$b->end_at->format('H:i').' · '.$b->duration_min.' min'),
                Tables\Columns\TextColumn::make('customer')->label(__('partner.customer'))->getStateUsing(fn (Booking $b) => $b->customerName())
                    ->description(fn (Booking $b) => $b->party.' '.__('partner.pers')),
                Tables\Columns\TextColumn::make('participants')->label(__('partner.treatments'))
                    ->getStateUsing(fn (Booking $b) => $b->participants->groupBy('treatment_name')->map(fn ($g, $n) => $g->sum('party') > 1 ? "$n ×".$g->sum('party') : $n)->implode(', '))
                    ->wrap(),
                Tables\Columns\TextColumn::make('total')->label(__('partner.total'))->money(config('hl.currency'), locale: 'fr')->sortable(),
                Tables\Columns\TextColumn::make('status')->label(__('partner.status'))->badge()
                    ->formatStateUsing(fn ($state) => __('ui.status')[$state] ?? $state)
                    ->color(fn ($state) => BookingActions::statusColor($state)),
                Tables\Columns\TextColumn::make('reference')->label(__('partner.reference'))->searchable()->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label(__('partner.status'))->options(__('ui.status'))->default('waiting')->multiple(),
                Tables\Filters\Filter::make('upcoming')->label(__('partner.upcoming'))->query(fn (Builder $q) => $q->where('start_at', '>=', now())),
            ])
            ->actions([Tables\Actions\ViewAction::make(), ...BookingActions::all('partner')])
            ->emptyStateHeading(__('partner.no_bookings'));
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema(BookingActions::infolist());
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBookings::route('/'),
            'view' => Pages\ViewBooking::route('/{record}'),
        ];
    }
}
