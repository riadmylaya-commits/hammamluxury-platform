<?php

namespace App\Filament\Partner\Widgets;

use App\Filament\Partner\Resources\BookingResource;
use App\Filament\Shared\BookingActions;
use App\Models\Booking;
use Filament\Facades\Filament;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class PendingBookings extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('partner.pending_heading'))
            ->query(Booking::where('spa_id', Filament::getTenant()->id)->where('status', 'waiting')->orderBy('start_at')->with('participants'))
            ->columns([
                Tables\Columns\TextColumn::make('start_at')->label(__('partner.date_time'))->dateTime('D d/m · H:i'),
                Tables\Columns\TextColumn::make('customer')->label(__('partner.customer'))->getStateUsing(fn (Booking $b) => $b->customerName().' · '.$b->party.' '.__('partner.pers')),
                Tables\Columns\TextColumn::make('participants')->label(__('partner.treatments'))
                    ->getStateUsing(fn (Booking $b) => $b->participants->pluck('treatment_name')->unique()->implode(', '))->wrap(),
                Tables\Columns\TextColumn::make('total')->label(__('partner.total'))->money(config('hl.currency'), locale: 'fr'),
                Tables\Columns\TextColumn::make('expires_at')->label(__('partner.expires_at'))->since()->color('warning'),
            ])
            ->actions([
                Tables\Actions\Action::make('view')->label(__('partner.view'))->url(fn (Booking $b) => BookingResource::getUrl('view', ['record' => $b])),
                ...BookingActions::all('partner'),
            ])
            ->paginated(false)
            ->emptyStateHeading(__('partner.no_pending'));
    }
}
