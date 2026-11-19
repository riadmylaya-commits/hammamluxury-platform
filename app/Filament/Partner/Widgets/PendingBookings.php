<?php

namespace App\Filament\Partner\Widgets;

use App\Filament\Partner\Resources\BookingResource;
use App\Filament\Shared\BookingActions;
use App\Models\Booking;
use Filament\Facades\Filament;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\HtmlString;

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
                // Téléphone : une carte par demande (aucun défilement horizontal) ; le tableau reste sur tablette et ordinateur.
                Tables\Columns\TextColumn::make('m_card')->label('')->state(fn (Booking $b) => static::mobileCard($b))->html()->wrap()->hiddenFrom('md'),
                Tables\Columns\TextColumn::make('start_at')->label(__('partner.date_time'))->dateTime('D d/m · H:i')->visibleFrom('md')
                    ->description(fn (Booking $b) => $b->last_minute ? __('partner.last_minute_badge', ['until' => $b->expires_at?->format('H\hi')]) : null),
                Tables\Columns\TextColumn::make('customer')->label(__('partner.customer'))->getStateUsing(fn (Booking $b) => $b->customerName().' · '.$b->party.' '.__('partner.pers'))->wrap()->visibleFrom('md'),
                Tables\Columns\TextColumn::make('participants')->label(__('partner.treatments'))
                    ->getStateUsing(fn (Booking $b) => $b->participants->pluck('treatment_name')->unique()->implode(', '))->wrap()->visibleFrom('md'),
                Tables\Columns\TextColumn::make('total')->label(__('partner.total'))->money(config('hl.currency'), locale: 'fr')->visibleFrom('md'),
                Tables\Columns\TextColumn::make('expires_at')->label(__('partner.expires_at'))->since()->color(fn ($record) => $record->last_minute ? 'danger' : 'warning')->visibleFrom('md'),
            ])
            ->actions([
                Tables\Actions\Action::make('view_m')->label(__('partner.view'))->url(fn (Booking $b) => BookingResource::getUrl('view', ['record' => $b]))->extraAttributes(['class' => 'hl-mobile-only']),
                Tables\Actions\ActionGroup::make(BookingActions::all('partner'))->label(__('partner.respond'))->button()->color('primary')->extraAttributes(['class' => 'hl-mobile-only']),
                Tables\Actions\Action::make('view')->label(__('partner.view'))->url(fn (Booking $b) => BookingResource::getUrl('view', ['record' => $b]))->extraAttributes(['class' => 'hl-desktop-only']),
                ...array_map(fn ($action) => $action->extraAttributes(['class' => 'hl-desktop-only'], merge: true), BookingActions::all('partner')),
            ])
            ->paginated(false)
            ->emptyStateHeading(__('partner.no_pending'));
    }

    /** Carte d'une demande en attente sur téléphone : date, repère « dernière minute », client, soins, total et heure limite. */
    public static function mobileCard(Booking $b): HtmlString
    {
        $muted = 'style="color:#6b7280;font-size:.8rem"';
        $urgent = $b->last_minute
            ? '<div><span style="display:inline-block;border-radius:.375rem;padding:.1rem .5rem;font-size:.75rem;font-weight:600;background:#fee2e2;color:#991b1b">'.e(__('partner.last_minute_badge', ['until' => $b->expires_at?->format('H\hi')])).'</span></div>'
            : '';

        return new HtmlString(implode('', [
            '<div style="font-weight:700">'.e($b->start_at->translatedFormat('D d/m · H:i')).'</div>',
            $urgent,
            '<div style="white-space:normal">'.e($b->customerName()).' <span '.$muted.'>· '.$b->party.' '.e(__('partner.pers')).'</span></div>',
            '<div style="white-space:normal" '.$muted.'>'.e($b->participants->pluck('treatment_name')->unique()->implode(', ')).'</div>',
            '<div><strong>'.e(number_format((float) $b->total, 0, ',', ' ')).' '.e(config('hl.currency')).'</strong>'
                .($b->expires_at ? ' <span '.$muted.'>· '.e(__('partner.expires_at')).' '.e($b->expires_at->format('d/m H\hi')).'</span>' : '').'</div>',
        ]));
    }
}
