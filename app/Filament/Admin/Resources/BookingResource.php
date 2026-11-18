<?php

namespace App\Filament\Admin\Resources;

use App\Domain\Policy\CancellationPolicy;
use App\Filament\Admin\Resources\BookingResource\Pages;
use App\Filament\Shared\BookingActions;
use App\Models\Booking;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

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

    /** Carte compacte d'une réservation pour la liste sur téléphone (les colonnes détaillées restent sur ordinateur). */
    public static function mobileCard(Booking $b): HtmlString
    {
        $palette = ['success' => 'background:#dcfce7;color:#166534', 'warning' => 'background:#fef3c7;color:#92400e', 'danger' => 'background:#fee2e2;color:#991b1b', 'info' => 'background:#dbeafe;color:#1e40af', 'gray' => 'background:#f3f4f6;color:#374151'];
        $badge = fn (string $label, string $color) => '<span style="display:inline-block;border-radius:.375rem;padding:.1rem .5rem;font-size:.75rem;font-weight:600;'.($palette[$color] ?? $palette['gray']).'">'.e($label).'</span>';
        $muted = 'style="color:#6b7280;font-size:.8rem"';

        return new HtmlString(implode('', [
            '<div style="font-weight:700">'.e($b->reference).' '.$badge(__('ui.status')[$b->status] ?? $b->status, BookingActions::statusColor($b->status)).'</div>',
            '<div style="white-space:normal">'.e((string) $b->spa?->name).'</div>',
            '<div>'.e($b->start_at->format('d/m/Y H:i')).' <span '.$muted.'>· '.e($b->customerName()).' · '.$b->party.' '.e(__('partner.pers')).'</span></div>',
            '<div><strong>'.e(number_format((float) $b->total, 0, ',', ' ')).' '.e(config('hl.currency')).'</strong></div>',
        ]));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['spa', 'participants']))
            ->columns([
                Tables\Columns\TextColumn::make('m_card')->label('')->state(fn (Booking $b) => static::mobileCard($b))->html()->wrap()->hiddenFrom('md'),
                Tables\Columns\TextColumn::make('reference')->visibleFrom('md')->label(__('partner.reference'))->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('status')->visibleFrom('md')->label(__('partner.status'))->badge()
                    ->formatStateUsing(fn ($state) => __('ui.status')[$state] ?? $state)
                    ->color(fn ($state) => BookingActions::statusColor($state)),
                Tables\Columns\TextColumn::make('spa.name')->visibleFrom('md')->label(__('admin.spa'))->searchable()->wrap(),
                Tables\Columns\TextColumn::make('start_at')->visibleFrom('md')->label(__('partner.date_time'))->dateTime('d/m/y H:i')->sortable(),
                Tables\Columns\TextColumn::make('customer')->visibleFrom('md')->label(__('partner.customer'))->getStateUsing(fn (Booking $b) => $b->customerName())->searchable(['first_name', 'last_name', 'email'])->wrap(),
                Tables\Columns\TextColumn::make('party')->visibleFrom('md')->label(__('partner.party')),
                Tables\Columns\TextColumn::make('total')->visibleFrom('md')->label(__('partner.total'))->money(config('hl.currency'), locale: 'fr')->sortable(),
                Tables\Columns\TextColumn::make('commission_amount')->visibleFrom('md')->label(__('partner.commission'))->getStateUsing(fn (Booking $b) => $b->commissionDue())->money(config('hl.currency'), locale: 'fr')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('rate_type')->visibleFrom('md')->label(__('partner.rate_type'))->badge()->color(fn ($state) => $state === 'non_refundable' ? 'success' : 'gray')->formatStateUsing(fn ($state) => __('partner.rate_types.'.$state))->toggleable()->visible(fn () => CancellationPolicy::nrEnabled())->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('payment_status')->visibleFrom('md')->label(__('partner.payment'))->badge()
                    ->formatStateUsing(fn ($state) => __('partner.payment_statuses')[$state] ?? $state)
                    ->color(fn ($state) => BookingActions::paymentColor((string) $state))->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('source')->visibleFrom('md')->label(__('partner.source'))->badge()->color(fn ($state) => $state === 'partner' ? 'info' : 'gray')
                    ->formatStateUsing(fn ($state, Booking $b) => $b->channel ? (__('partner.channels')[$b->channel] ?? $b->channel) : __('partner.sources')[$state] ?? $state)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('source')->label(__('partner.source'))->options(__('partner.sources')),
                Tables\Filters\SelectFilter::make('status')->options(__('ui.status'))->multiple(),
                Tables\Filters\SelectFilter::make('spa_id')->label(__('admin.spa'))->relationship('spa', 'name'),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([Tables\Actions\ViewAction::make(), ...BookingActions::all('admin')])->extraAttributes(['class' => 'hl-mobile-only']),
                Tables\Actions\ViewAction::make()->extraAttributes(['class' => 'hl-desktop-only']),
                Tables\Actions\ActionGroup::make(BookingActions::all('admin'))->extraAttributes(['class' => 'hl-desktop-only']),
            ]);
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
