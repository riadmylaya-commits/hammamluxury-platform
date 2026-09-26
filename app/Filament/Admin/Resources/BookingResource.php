<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\BookingResource\Pages;
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

    protected static ?int $navigationSort = 30;

    public static function getModelLabel(): string
    {
        return __('partner.booking');
    }

    public static function getPluralModelLabel(): string
    {
        return __('partner.bookings');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['spa', 'participants']))
            ->columns([
                Tables\Columns\TextColumn::make('reference')->label(__('partner.reference'))->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('spa.name')->label(__('admin.spa'))->searchable(),
                Tables\Columns\TextColumn::make('start_at')->label(__('partner.date_time'))->dateTime('d/m/Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('customer')->label(__('partner.customer'))->getStateUsing(fn (Booking $b) => $b->customerName())->searchable(['first_name', 'last_name', 'email']),
                Tables\Columns\TextColumn::make('party')->label(__('partner.party')),
                Tables\Columns\TextColumn::make('total')->label(__('partner.total'))->money(config('hl.currency'), locale: 'fr')->sortable(),
                Tables\Columns\TextColumn::make('commission_amount')->label(__('partner.commission'))->money(config('hl.currency'), locale: 'fr'),
                Tables\Columns\TextColumn::make('payment_status')->label(__('partner.payment'))->badge()
                    ->formatStateUsing(fn ($state) => __('partner.payment_statuses')[$state] ?? $state)
                    ->color(fn ($state) => BookingActions::paymentColor((string) $state))->toggleable(),
                Tables\Columns\TextColumn::make('status')->label(__('partner.status'))->badge()
                    ->formatStateUsing(fn ($state) => __('ui.status')[$state] ?? $state)
                    ->color(fn ($state) => BookingActions::statusColor($state)),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(__('ui.status'))->multiple(),
                Tables\Filters\SelectFilter::make('spa_id')->label(__('admin.spa'))->relationship('spa', 'name'),
            ])
            ->actions([Tables\Actions\ViewAction::make(), ...BookingActions::all('admin')]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema(BookingActions::infolist(withCommission: true));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBookings::route('/'),
            'view' => Pages\ViewBooking::route('/{record}'),
        ];
    }
}
