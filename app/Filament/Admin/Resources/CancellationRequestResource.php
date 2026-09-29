<?php

namespace App\Filament\Admin\Resources;

use App\Domain\Booking\BookingException;
use App\Domain\Booking\CancellationService;
use App\Filament\Admin\Resources\CancellationRequestResource\Pages;
use App\Filament\Shared\BookingActions;
use App\Models\CancellationRequest;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
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

/** Demandes d'annulation partenaire : information client, réponse, décision finale HammamLuxury, historique. */
class CancellationRequestResource extends Resource
{
    protected static ?string $model = CancellationRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-hand-raised';

    protected static ?int $navigationSort = 35;

    public static function getModelLabel(): string
    {
        return __('admin.cancellation_request');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.cancellation_requests');
    }

    public static function getNavigationBadge(): ?string
    {
        $n = CancellationRequest::where('status', 'pending')->count();

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

    /** @return array<Tables\Actions\Action|Action> */
    public static function decisionActions(string $class = Tables\Actions\Action::class): array
    {
        $decide = function (CancellationRequest $r, string $decision, ?string $note): void {
            try {
                app(CancellationService::class)->decide($r, auth()->user(), $decision, $note);
                Notification::make()->title(__('partner.action_done'))->success()->send();
            } catch (BookingException $e) {
                Notification::make()->title(__('partner.action_failed'))->body($e->getMessage())->danger()->send();
            }
        };
        $note = Textarea::make('note')->label(__('partner.decision_note'))->rows(3)->maxLength(1000);

        return [
            $class::make('acceptRequest')->label(__('admin.accept_cancellation'))->icon('heroicon-o-check')->color('danger')
                ->visible(fn (CancellationRequest $r) => $r->isPending())
                ->form([$note])->requiresConfirmation()->modalDescription(__('admin.accept_cancellation_help'))
                ->action(fn (CancellationRequest $r, array $data) => $decide($r, 'accepted', $data['note'] ?? null)),
            $class::make('refuseRequest')->label(__('admin.refuse_cancellation'))->icon('heroicon-o-shield-check')->color('success')
                ->visible(fn (CancellationRequest $r) => $r->isPending())
                ->form([$note])->requiresConfirmation()->modalDescription(__('admin.refuse_cancellation_help'))
                ->action(fn (CancellationRequest $r, array $data) => $decide($r, 'refused', $data['note'] ?? null)),
            $class::make('proposeDate')->label(__('admin.propose_date'))->icon('heroicon-o-calendar-days')->color('info')
                ->visible(fn (CancellationRequest $r) => $r->isPending() && ! $r->hasOpenProposal())
                ->form([
                    DateTimePicker::make('start_at')->label(__('partner.proposed_start_at'))->required()->seconds(false)->minutesStep(15)->native(false)->minDate(now()),
                    Textarea::make('note')->label(__('admin.proposal_note'))->rows(3)->maxLength(1000)->placeholder(__('admin.proposal_note_placeholder')),
                ])
                ->modalDescription(__('admin.propose_date_help'))
                ->action(function (CancellationRequest $r, array $data) {
                    try {
                        app(CancellationService::class)->propose($r, auth()->user(), CarbonImmutable::parse($data['start_at']), $data['note'] ?? null);
                        Notification::make()->title(__('admin.proposal_sent'))->success()->send();
                    } catch (BookingException $e) {
                        Notification::make()->title(__('partner.action_failed'))->body($e->getMessage())->danger()->send();
                    }
                }),
            $class::make('evidence')->label(__('admin.view_evidence'))->icon('heroicon-o-paper-clip')->color('gray')
                ->visible(fn (CancellationRequest $r) => $r->evidence_path && Storage::disk('local')->exists($r->evidence_path))
                ->action(fn (CancellationRequest $r) => Storage::disk('local')->download($r->evidence_path)),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['booking', 'spa.partner', 'requester']))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label(__('partner.requested_at'))->dateTime('d/m/Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('booking.reference')->label(__('partner.reference'))->searchable()->weight('bold')
                    ->url(fn (CancellationRequest $r) => BookingResource::getUrl('view', ['record' => $r->booking_id])),
                Tables\Columns\TextColumn::make('spa.name')->label(__('admin.spa'))->searchable()->description(fn (CancellationRequest $r) => $r->spa->partner?->company_name),
                Tables\Columns\TextColumn::make('booking.start_at')->label(__('partner.date_time'))->dateTime('d/m/Y H:i'),
                Tables\Columns\TextColumn::make('booking.customer')->label(__('partner.customer'))->getStateUsing(fn (CancellationRequest $r) => $r->booking->customerName()),
                Tables\Columns\TextColumn::make('reason_code')->label(__('partner.cancellation_reason_code'))->badge()->color('gray')->placeholder('—')
                    ->formatStateUsing(fn ($state) => __('partner.cancellation_reason_codes')[$state] ?? $state)
                    ->icon(fn (CancellationRequest $r) => $r->evidence_path ? 'heroicon-o-paper-clip' : null),
                Tables\Columns\TextColumn::make('reason')->label(__('partner.cancellation_reason'))->limit(60)->wrap()->tooltip(fn (CancellationRequest $r) => $r->reason),
                Tables\Columns\TextColumn::make('step')->label(__('partner.status'))->badge()->getStateUsing(fn (CancellationRequest $r) => $r->step())
                    ->formatStateUsing(fn ($state) => __('partner.cancellation_steps')[$state] ?? $state)
                    ->color(fn ($state, CancellationRequest $r) => BookingActions::requestColor($r->status)),
                Tables\Columns\TextColumn::make('decided_at')->label(__('partner.decided_at'))->dateTime('d/m/Y H:i')->placeholder('—')->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label(__('partner.status'))->options(__('partner.cancellation_statuses'))->default('pending')->multiple(),
                Tables\Filters\SelectFilter::make('spa_id')->label(__('admin.spa'))->relationship('spa', 'name'),
            ])
            ->actions([Tables\Actions\ViewAction::make(), ...static::decisionActions()])
            ->emptyStateHeading(__('admin.no_cancellation_requests'));
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make(__('admin.cancellation_request'))->schema([
                TextEntry::make('booking.reference')->label(__('partner.reference'))->weight('bold')
                    ->url(fn (CancellationRequest $r) => BookingResource::getUrl('view', ['record' => $r->booking_id])),
                TextEntry::make('spa.name')->label(__('admin.spa')),
                TextEntry::make('spa.partner.company_name')->label(__('admin.partner'))->placeholder('—'),
                TextEntry::make('requester.name')->label(__('partner.requested_by'))->placeholder('—'),
                TextEntry::make('booking.start_at')->label(__('partner.date_time'))->dateTime('l d F Y · H:i'),
                TextEntry::make('booking.customer')->label(__('partner.customer'))->getStateUsing(fn (CancellationRequest $r) => $r->booking->customerName()),
                TextEntry::make('booking.status')->label(__('admin.booking_status'))->badge()
                    ->formatStateUsing(fn ($state) => __('ui.status')[$state] ?? $state)->color(fn ($state) => BookingActions::statusColor($state)),
                TextEntry::make('booking.total')->label(__('partner.total'))->money(config('hl.currency'), locale: 'fr'),
                TextEntry::make('reason_code')->label(__('partner.cancellation_reason_code'))->badge()->color('gray')->placeholder('—')
                    ->formatStateUsing(fn ($state) => __('partner.cancellation_reason_codes')[$state] ?? $state),
                TextEntry::make('evidence_path')->label(__('partner.cancellation_evidence'))->placeholder('—')
                    ->formatStateUsing(fn () => __('admin.evidence_attached')),
                TextEntry::make('previous')->label(__('admin.previous_requests'))->badge()->color(fn ($state) => $state > 0 ? 'warning' : 'gray')
                    ->getStateUsing(fn (CancellationRequest $r) => CancellationRequest::where('spa_id', $r->spa_id)->where('id', '!=', $r->id)->where('created_at', '>=', now()->subDays(90))->count())
                    ->helperText(__('admin.previous_requests_help')),
                TextEntry::make('reason')->label(__('partner.cancellation_reason'))->columnSpanFull(),
            ])->columns(4),

            Section::make(__('admin.cancellation_timeline'))->schema([
                TextEntry::make('created_at')->label(__('partner.cancellation_steps.received'))->dateTime('d/m/Y H:i'),
                TextEntry::make('client_notified_at')->label(__('admin.client_informed'))->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make('client_response')->label(__('partner.client_response'))->badge()->placeholder(__('partner.no_response_yet'))
                    ->formatStateUsing(fn ($state) => __('partner.client_responses')[$state] ?? $state)->color(fn ($state) => $state === 'accepted' ? 'success' : 'danger')
                    ->helperText(fn (CancellationRequest $r) => $r->client_responded_at?->format('d/m/Y H:i')),
                TextEntry::make('proposed_start_at')->label(__('partner.proposed_start_at'))->dateTime('d/m/Y H:i')->placeholder('—')
                    ->helperText(fn (CancellationRequest $r) => $r->proposal_response ? (__('partner.proposal_responses')[$r->proposal_response] ?? $r->proposal_response).' · '.$r->proposal_responded_at?->format('d/m/Y H:i') : ($r->proposed_at ? __('partner.no_response_yet') : null)),
                TextEntry::make('proposal_note')->label(__('admin.proposal_note'))->placeholder('—')->visible(fn (CancellationRequest $r) => $r->proposal_note),
                TextEntry::make('status')->label(__('admin.final_decision'))->badge()
                    ->formatStateUsing(fn ($state) => __('partner.cancellation_statuses')[$state] ?? $state)->color(fn ($state) => BookingActions::requestColor($state))
                    ->helperText(fn (CancellationRequest $r) => trim(($r->decided_at?->format('d/m/Y H:i') ?? '').' '.($r->decider?->name ?? ''))),
                TextEntry::make('decision_note')->label(__('partner.decision_note'))->placeholder('—')->columnSpanFull(),
            ])->columns(4),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCancellationRequests::route('/'),
            'view' => Pages\ViewCancellationRequest::route('/{record}'),
        ];
    }
}
