<?php

namespace App\Filament\Admin\Resources;

use App\Domain\Booking\BookingException;
use App\Domain\Review\ReviewService;
use App\Filament\Admin\Resources\ReviewResource\Pages;
use App\Models\Review;
use App\Models\Spa;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Modération des avis vérifiés et des réponses d'établissement. Un refus exige un motif lié au contenu
 * (insultes, fraude / hors sujet, données personnelles, photo inappropriée, autre) : la note seule n'en est jamais un.
 */
class ReviewResource extends Resource
{
    protected static ?string $model = Review::class;

    protected static ?string $navigationIcon = 'heroicon-o-star';

    protected static ?int $navigationSort = 50;

    public static function getModelLabel(): string
    {
        return __('admin.review');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.reviews');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('status', '!=', 'invited');
    }

    public static function getNavigationBadge(): ?string
    {
        $n = Review::where('status', 'pending')->orWhere('reply_status', 'pending')->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /** @return array<Tables\Actions\Action|Action> */
    public static function moderationActions(string $class = Tables\Actions\Action::class): array
    {
        $run = function (callable $fn): void {
            try {
                $fn(app(ReviewService::class), auth()->user());
                Notification::make()->title(__('partner.action_done'))->success()->send();
            } catch (BookingException $e) {
                Notification::make()->title(__('partner.action_failed'))->body($e->getMessage())->danger()->send();
            }
        };
        $reasonForm = fn (string $note) => [
            Select::make('reason')->label(__('admin.review_rejection_reason'))->options(__('admin.review_rejection_reasons'))->required()->live()
                ->helperText(__('admin.review_rejection_hint')),
            Textarea::make('note')->label(__('admin.review_rejection_note'))->rows(3)->required(fn ($get) => $get('reason') === 'other')->visible(fn () => $note === 'review'),
        ];

        return [
            $class::make('publish')->label(__('admin.publish'))->icon('heroicon-o-check')->color('success')
                ->visible(fn (Review $r) => $r->status === 'pending' || $r->status === 'rejected')
                ->requiresConfirmation()->modalDescription(__('admin.review_publish_confirm'))
                ->action(fn (Review $r) => $run(fn (ReviewService $s, $u) => $s->moderate($r, $u, 'published'))),
            $class::make('reject')->label(__('admin.reject'))->icon('heroicon-o-x-mark')->color('danger')
                ->visible(fn (Review $r) => $r->status === 'pending' || $r->status === 'published')
                ->form($reasonForm('review'))
                ->action(fn (Review $r, array $data) => $run(fn (ReviewService $s, $u) => $s->moderate($r, $u, 'rejected', $data['reason'], $data['note'] ?? null))),
            $class::make('publish_reply')->label(__('admin.review_publish_reply'))->icon('heroicon-o-chat-bubble-left-ellipsis')->color('success')
                ->visible(fn (Review $r) => $r->reply !== null && $r->reply_status !== 'published')
                ->requiresConfirmation()->modalDescription(fn (Review $r) => $r->reply)
                ->action(fn (Review $r) => $run(fn (ReviewService $s, $u) => $s->moderateReply($r, $u, 'published'))),
            $class::make('reject_reply')->label(__('admin.review_reject_reply'))->icon('heroicon-o-chat-bubble-left')->color('danger')
                ->visible(fn (Review $r) => $r->reply !== null && $r->reply_status !== 'rejected')
                ->form($reasonForm('reply'))
                ->action(fn (Review $r, array $data) => $run(fn (ReviewService $s, $u) => $s->moderateReply($r, $u, 'rejected', $data['reason']))),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('submitted_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['spa', 'booking'])->withCount('photos'))
            ->columns([
                Tables\Columns\TextColumn::make('spa.name')->label(__('admin.spa'))->searchable(),
                Tables\Columns\TextColumn::make('author_name')->label(__('admin.author'))->description(fn (Review $r) => $r->booking?->reference),
                Tables\Columns\TextColumn::make('rating')->label(__('admin.rating'))->formatStateUsing(fn ($state) => str_repeat('★', (int) $state).str_repeat('☆', 5 - (int) $state))->sortable(),
                Tables\Columns\TextColumn::make('body')->label(__('admin.review'))->limit(80)->wrap()->description(fn (Review $r) => $r->title),
                Tables\Columns\TextColumn::make('photos_count')->label(__('admin.review_photos'))->badge()->color('gray')->formatStateUsing(fn ($state) => $state ?: null),
                Tables\Columns\IconColumn::make('booking_id')->label(__('admin.verified'))->boolean()->getStateUsing(fn (Review $r) => $r->isVerified()),
                Tables\Columns\TextColumn::make('status')->label(__('partner.status'))->badge()
                    ->formatStateUsing(fn ($state) => __("admin.r_$state"))
                    ->color(fn ($state) => match ($state) {
                        'published' => 'success', 'pending' => 'warning', default => 'danger'
                    }),
                Tables\Columns\TextColumn::make('reply_status')->label(__('admin.review_reply'))->badge()->placeholder('—')
                    ->formatStateUsing(fn ($state) => __("admin.r_$state"))
                    ->color(fn ($state) => match ($state) {
                        'published' => 'success', 'pending' => 'warning', default => 'danger'
                    }),
                Tables\Columns\TextColumn::make('submitted_at')->label(__('admin.review_submitted_at'))->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'pending' => __('admin.r_pending'),
                    'published' => __('admin.r_published'),
                    'rejected' => __('admin.r_rejected'),
                ])->default('pending'),
                Tables\Filters\TernaryFilter::make('reply_pending')->label(__('admin.review_reply_pending'))
                    ->queries(true: fn (Builder $q) => $q->where('reply_status', 'pending'), false: fn (Builder $q) => $q->where(fn ($q) => $q->whereNull('reply_status')->orWhere('reply_status', '!=', 'pending'))),
            ])
            ->actions([Tables\Actions\ViewAction::make(), ...self::moderationActions()]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        $smiley = fn ($v) => [1 => '🙁', 2 => '😐', 3 => '😊'][(int) $v] ?? '—';

        return $infolist->schema([
            Section::make(__('admin.review'))->schema([
                TextEntry::make('spa.name')->label(__('admin.spa')),
                TextEntry::make('author_name')->label(__('admin.author')),
                TextEntry::make('booking.reference')->label(__('partner.reference'))->placeholder('—')
                    ->url(fn (Review $r) => $r->booking_id ? BookingResource::getUrl('view', ['record' => $r->booking_id]) : null),
                TextEntry::make('booking.start_at')->label(__('partner.date_time'))->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make('rating')->label(__('admin.rating'))->formatStateUsing(fn ($state) => str_repeat('★', (int) $state).str_repeat('☆', 5 - (int) $state).' ('.$state.'/5)'),
                TextEntry::make('status')->label(__('partner.status'))->badge()->formatStateUsing(fn ($state) => __("admin.r_$state"))
                    ->color(fn ($state) => match ($state) {
                        'published' => 'success', 'pending' => 'warning', default => 'danger'
                    }),
                TextEntry::make('title')->label(__('ui.review.title'))->placeholder('—')->columnSpanFull(),
                TextEntry::make('body')->label(__('ui.review.body'))->columnSpanFull(),
                TextEntry::make('liked')->label(__('ui.review.liked'))->placeholder('—'),
                TextEntry::make('improve')->label(__('ui.review.improve'))->placeholder('—'),
                TextEntry::make('criteria')->label(__('ui.review.criteria_title'))->placeholder('—')->columnSpanFull()
                    ->formatStateUsing(fn ($state, Review $r) => collect($r->criteria ?? [])->map(fn ($v, $k) => __('ui.review.criteria.'.$k).' '.$smiley($v))->implode(' · ')),
                TextEntry::make('bonus')->label(__('ui.review.bonus_title'))->placeholder('—')->columnSpanFull()
                    ->formatStateUsing(fn ($state, Review $r) => collect($r->bonus ?? [])->map(fn ($v, $k) => __('ui.review.bonus.'.$k).' : '.__('ui.review.'.$v))->implode(' · ')),
                ImageEntry::make('photos')->label(__('admin.review_photos'))->columnSpanFull()->height(140)
                    ->getStateUsing(fn (Review $r) => $r->photos->map(fn ($p) => $p->publicUrl() ?? route('review.photo', $p))->all())
                    ->visible(fn (Review $r) => $r->photos->isNotEmpty()),
                TextEntry::make('rejection_reason')->label(__('admin.review_rejection_reason'))->badge()->color('danger')->placeholder('—')
                    ->formatStateUsing(fn ($state) => __('admin.review_rejection_reasons.'.$state)),
                TextEntry::make('rejection_note')->label(__('admin.review_rejection_note'))->placeholder('—'),
                TextEntry::make('moderator.name')->label(__('admin.review_moderated_by'))->placeholder('—'),
                TextEntry::make('moderated_at')->label(__('admin.review_moderated_at'))->dateTime('d/m/Y H:i')->placeholder('—'),
            ])->columns(2),
            Section::make(__('admin.review_reply'))->schema([
                TextEntry::make('reply')->label(__('admin.review_reply'))->columnSpanFull(),
                TextEntry::make('reply_status')->label(__('partner.status'))->badge()->formatStateUsing(fn ($state) => __("admin.r_$state"))
                    ->color(fn ($state) => match ($state) {
                        'published' => 'success', 'pending' => 'warning', default => 'danger'
                    }),
                TextEntry::make('reply_at')->label(__('admin.review_submitted_at'))->dateTime('d/m/Y H:i'),
                TextEntry::make('reply_rejection_reason')->label(__('admin.review_rejection_reason'))->badge()->color('danger')->placeholder('—')
                    ->formatStateUsing(fn ($state) => __('admin.review_rejection_reasons.'.$state)),
            ])->columns(3)->visible(fn (Review $r) => $r->reply !== null),
        ]);
    }

    public static function recomputeRating(Spa $spa): void
    {
        app(ReviewService::class)->recomputeRating($spa);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListReviews::route('/'), 'view' => Pages\ViewReview::route('/{record}')];
    }
}
