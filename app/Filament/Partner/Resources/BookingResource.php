<?php

namespace App\Filament\Partner\Resources;

use App\Filament\Partner\Resources\BookingResource\Pages;
use App\Filament\Shared\BookingActions;
use App\Models\Booking;
use App\Models\BookingMessage;
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
        $n = static::getEloquentQuery()->where('status', 'waiting')->count() + static::unreadMessages();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return static::unreadMessages() ? 'danger' : 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('partner.badge_tooltip');
    }

    /** Messages clients non lus sur l'établissement courant. */
    public static function unreadMessages(): int
    {
        return BookingMessage::whereIn('booking_id', static::getEloquentQuery()->select('bookings.id'))->unreadFor('partner')->count();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('start_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('participants')->withCount(['messages as unread_count' => fn ($q) => $q->unreadFor('partner')]))
            ->columns([
                Tables\Columns\TextColumn::make('m_card')->label('')->state(fn (Booking $b) => static::mobileCard($b))->html()->wrap()->hiddenFrom('md'),
                Tables\Columns\TextColumn::make('start_at')->label(__('partner.date_time'))->dateTime('D d/m · H:i')->sortable()->visibleFrom('md')
                    ->description(fn (Booking $b) => '→ '.$b->end_at->format('H:i').' · '.$b->duration_min.' min'),
                Tables\Columns\TextColumn::make('customer')->label(__('partner.customer'))->getStateUsing(fn (Booking $b) => $b->customerName())->visibleFrom('md')
                    ->description(fn (Booking $b) => $b->party.' '.__('partner.pers')),
                Tables\Columns\TextColumn::make('participants')->label(__('partner.treatments'))
                    ->getStateUsing(fn (Booking $b) => static::treatmentsLine($b))
                    ->wrap()->visibleFrom('md'),
                Tables\Columns\TextColumn::make('total')->label(__('partner.total'))->money(config('hl.currency'), locale: 'fr')->sortable()->visibleFrom('md'),
                Tables\Columns\TextColumn::make('source')->label(__('partner.source'))->badge()->color(fn ($state) => $state === 'partner' ? 'info' : 'gray')->visibleFrom('md')
                    ->formatStateUsing(fn ($state, Booking $b) => $b->channel ? (__('partner.channels')[$b->channel] ?? $b->channel) : __('partner.sources')[$state] ?? $state),
                Tables\Columns\TextColumn::make('status')->label(__('partner.status'))->badge()->visibleFrom('md')
                    ->formatStateUsing(fn ($state) => __('ui.status')[$state] ?? $state)
                    ->color(fn ($state) => BookingActions::statusColor($state)),
                Tables\Columns\TextColumn::make('unread_count')->label('')->badge()->color('danger')->icon('heroicon-o-chat-bubble-left-right')->visibleFrom('md')
                    ->formatStateUsing(fn ($state) => $state ?: null)->tooltip(__('partner.unread_messages')),
                Tables\Columns\TextColumn::make('reference')->label(__('partner.reference'))->searchable()->toggleable()->visibleFrom('md'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('source')->label(__('partner.source'))->options(__('partner.sources')),
                Tables\Filters\SelectFilter::make('status')->label(__('partner.status'))->options(__('ui.status'))->default('waiting')->multiple(),
                Tables\Filters\Filter::make('upcoming')->label(__('partner.upcoming'))->query(fn (Builder $query) => $query->where('start_at', '>=', now())),
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
            'calendar' => Pages\CalendarBookings::route('/calendrier'),
            'view' => Pages\ViewBooking::route('/{record}'),
        ];
    }

    public static function treatmentsLine(Booking $b): string
    {
        return $b->participants->groupBy('treatment_name')->map(fn ($g, $n) => $g->sum('party') > 1 ? "$n ×".$g->sum('party') : $n)->implode(', ');
    }

    /** Carte empilée (mobile) : une réservation par carte, sans défilement horizontal. */
    public static function mobileCard(Booking $b): HtmlString
    {
        $muted = 'style="color:#6b7280;font-size:.8rem"';
        $status = __('ui.status')[$b->status] ?? $b->status;
        $color = match (BookingActions::statusColor($b->status)) {
            'success' => 'background:#dcfce7;color:#166534', 'warning' => 'background:#fef3c7;color:#92400e', 'danger' => 'background:#fee2e2;color:#991b1b', 'info' => 'background:#dbeafe;color:#1e40af', default => 'background:#f3f4f6;color:#374151',
        };
        $badge = '<span style="display:inline-block;border-radius:.375rem;padding:.1rem .5rem;font-size:.75rem;font-weight:600;'.$color.'">'.e($status).'</span>';
        $source = $b->channel ? (__('partner.channels')[$b->channel] ?? $b->channel) : (__('partner.sources')[$b->source] ?? $b->source);
        $parts = [
            '<div style="font-weight:700">'.e($b->start_at->translatedFormat('D d/m · H:i')).' <span '.$muted.'>→ '.e($b->end_at->format('H:i')).' · '.$b->duration_min.' min</span></div>',
            '<div>'.e($b->customerName()).' <span '.$muted.'>· '.$b->party.' '.e(__('partner.pers')).'</span></div>',
            '<div style="white-space:normal">'.e(static::treatmentsLine($b)).'</div>',
            '<div style="margin-top:.3rem">'.$badge.' <strong>'.e(number_format((float) $b->total, 0, ',', ' ')).' '.e(config('hl.currency')).'</strong>'
                .($b->unread_count ? ' <span style="color:#991b1b;font-weight:600">✉ '.$b->unread_count.'</span>' : '').'</div>',
            '<div '.$muted.'>'.e($b->reference.' · '.$source).'</div>',
        ];

        return new HtmlString('<div style="display:flex;flex-direction:column;gap:.15rem;max-width:100%">'.implode('', $parts).'</div>');
    }
}
