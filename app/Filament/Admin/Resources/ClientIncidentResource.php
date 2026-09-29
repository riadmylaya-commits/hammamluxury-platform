<?php

namespace App\Filament\Admin\Resources;

use App\Domain\Booking\BookingException;
use App\Domain\Booking\IncidentService;
use App\Domain\Phone\PhoneNumber;
use App\Filament\Admin\Resources\ClientIncidentResource\Pages;
use App\Models\ClientIncident;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

/** Signalements de comportement client émis par les établissements : examen humain HammamLuxury, récidives inter-établissements. */
class ClientIncidentResource extends Resource
{
    protected static ?string $model = ClientIncident::class;

    protected static ?string $navigationIcon = 'heroicon-o-flag';

    protected static ?int $navigationSort = 36;

    public static function getModelLabel(): string
    {
        return __('admin.client_incident');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.client_incidents');
    }

    public static function getNavigationBadge(): ?string
    {
        $n = ClientIncident::where('status', 'open')->count();

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

    public static function canDelete($record): bool
    {
        return false;
    }

    /** @return array<Tables\Actions\Action|Action> */
    public static function reviewActions(string $class = Tables\Actions\Action::class): array
    {
        $review = function (ClientIncident $i, string $status, ?string $note): void {
            try {
                app(IncidentService::class)->review($i, auth()->user(), $status, $note);
                Notification::make()->title(__('partner.action_done'))->success()->send();
            } catch (BookingException $e) {
                Notification::make()->title(__('partner.action_failed'))->body($e->getMessage())->danger()->send();
            }
        };
        $note = Textarea::make('note')->label(__('admin.incident_admin_note'))->rows(3)->maxLength(1000);

        return [
            $class::make('reviewIncident')->label(__('admin.incident_review'))->icon('heroicon-o-check')->color('warning')
                ->visible(fn (ClientIncident $i) => $i->status === 'open')
                ->form([$note])->requiresConfirmation()
                ->action(fn (ClientIncident $i, array $data) => $review($i, 'reviewed', $data['note'] ?? null)),
            $class::make('dismissIncident')->label(__('admin.incident_dismiss'))->icon('heroicon-o-x-mark')->color('gray')
                ->visible(fn (ClientIncident $i) => $i->status === 'open')
                ->form([$note])->requiresConfirmation()
                ->action(fn (ClientIncident $i, array $data) => $review($i, 'dismissed', $data['note'] ?? null)),
            $class::make('evidence')->label(__('admin.view_evidence'))->icon('heroicon-o-paper-clip')->color('gray')
                ->visible(fn (ClientIncident $i) => $i->evidence_path && Storage::disk('local')->exists($i->evidence_path))
                ->action(fn (ClientIncident $i) => Storage::disk('local')->download($i->evidence_path)),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['booking', 'spa', 'reporter']))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label(__('partner.requested_at'))->dateTime('d/m/Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('booking.reference')->label(__('partner.reference'))->searchable()->weight('bold')
                    ->url(fn (ClientIncident $i) => BookingResource::getUrl('view', ['record' => $i->booking_id])),
                Tables\Columns\TextColumn::make('spa.name')->label(__('admin.spa'))->searchable(),
                Tables\Columns\TextColumn::make('client')->label(__('admin.incident_client'))->getStateUsing(fn (ClientIncident $i) => $i->booking->customerName())
                    ->description(fn (ClientIncident $i) => PhoneNumber::format($i->client_phone))->searchable(['client_phone', 'client_email']),
                Tables\Columns\TextColumn::make('category')->label(__('admin.incident_category'))->badge()->color('warning')
                    ->formatStateUsing(fn ($state) => __('admin.incident_categories')[$state] ?? $state)
                    ->icon(fn (ClientIncident $i) => $i->evidence_path ? 'heroicon-o-paper-clip' : null),
                Tables\Columns\TextColumn::make('other_spas')->label(__('admin.incident_other_spas'))->badge()
                    ->getStateUsing(fn (ClientIncident $i) => $i->otherSpasCount())->color(fn ($state) => $state > 0 ? 'danger' : 'gray'),
                Tables\Columns\TextColumn::make('status')->label(__('partner.status'))->badge()
                    ->formatStateUsing(fn ($state) => __('admin.incident_statuses')[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'open' => 'warning', 'reviewed' => 'danger', default => 'gray'
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label(__('partner.status'))->options(__('admin.incident_statuses'))->default('open')->multiple(),
                Tables\Filters\SelectFilter::make('category')->label(__('admin.incident_category'))->options(__('admin.incident_categories')),
                Tables\Filters\SelectFilter::make('spa_id')->label(__('admin.spa'))->relationship('spa', 'name'),
            ])
            ->actions([Tables\Actions\ViewAction::make(), ...static::reviewActions()])
            ->emptyStateHeading(__('admin.no_client_incidents'));
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make(__('admin.client_incident'))->schema([
                TextEntry::make('booking.reference')->label(__('partner.reference'))->weight('bold')
                    ->url(fn (ClientIncident $i) => BookingResource::getUrl('view', ['record' => $i->booking_id])),
                TextEntry::make('spa.name')->label(__('admin.spa')),
                TextEntry::make('reporter.name')->label(__('admin.incident_reporter'))->placeholder('—'),
                TextEntry::make('booking.start_at')->label(__('partner.date_time'))->dateTime('l d F Y · H:i'),
                TextEntry::make('client')->label(__('admin.incident_client'))->getStateUsing(fn (ClientIncident $i) => $i->booking->customerName()),
                TextEntry::make('client_phone')->label(__('partner.phone'))->formatStateUsing(fn (?string $state) => PhoneNumber::format($state))->placeholder('—'),
                TextEntry::make('client_email')->label('E-mail')->placeholder('—'),
                TextEntry::make('other_spas')->label(__('admin.incident_other_spas'))->badge()
                    ->getStateUsing(fn (ClientIncident $i) => $i->otherSpasCount())->color(fn ($state) => $state > 0 ? 'danger' : 'gray')
                    ->helperText(__('admin.incident_other_spas_help')),
                TextEntry::make('category')->label(__('admin.incident_category'))->badge()->color('warning')
                    ->formatStateUsing(fn ($state) => __('admin.incident_categories')[$state] ?? $state),
                TextEntry::make('evidence_path')->label(__('partner.report_evidence'))->placeholder('—')->formatStateUsing(fn () => __('admin.evidence_attached')),
                TextEntry::make('description')->label(__('admin.incident_description'))->columnSpanFull(),
            ])->columns(4),

            Section::make(__('admin.final_decision'))->schema([
                TextEntry::make('status')->label(__('partner.status'))->badge()
                    ->formatStateUsing(fn ($state) => __('admin.incident_statuses')[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'open' => 'warning', 'reviewed' => 'danger', default => 'gray'
                    }),
                TextEntry::make('reviewer.name')->label(__('admin.incident_reviewed_by'))->placeholder('—'),
                TextEntry::make('reviewed_at')->label(__('partner.decided_at'))->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make('admin_note')->label(__('admin.incident_admin_note'))->placeholder('—')->columnSpanFull(),
            ])->columns(3),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListClientIncidents::route('/'),
            'view' => Pages\ViewClientIncident::route('/{record}'),
        ];
    }
}
