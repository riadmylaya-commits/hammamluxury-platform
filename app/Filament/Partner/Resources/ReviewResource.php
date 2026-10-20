<?php

namespace App\Filament\Partner\Resources;

use App\Domain\Booking\BookingException;
use App\Domain\Review\ReviewService;
use App\Filament\Partner\Resources\ReviewResource\Pages;
use App\Models\Review;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/** Avis publiés sur l'établissement : lecture et réponse (modérée par HammamLuxury avant publication). */
class ReviewResource extends Resource
{
    protected static ?string $model = Review::class;

    protected static ?string $navigationIcon = 'heroicon-o-star';

    protected static ?int $navigationSort = 45;

    public static function getModelLabel(): string
    {
        return __('partner.review');
    }

    public static function getPluralModelLabel(): string
    {
        return __('partner.reviews');
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
        return parent::getEloquentQuery()->published();
    }

    public static function getNavigationBadge(): ?string
    {
        $n = static::getEloquentQuery()->whereNull('reply')->count();

        return $n ? (string) $n : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('published_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['booking', 'photos']))
            ->columns([
                // Mobile : une carte par avis (note, date, client, critères, commentaire, statut, puis les actions).
                Tables\Columns\Layout\Stack::make([
                    Tables\Columns\TextColumn::make('m_rating')->state(fn (Review $r) => static::stars($r->rating))->weight('bold')->size('lg')
                        ->description(fn (Review $r) => static::criteriaLine($r->criteriaScores()))->wrap(),
                    Tables\Columns\TextColumn::make('m_meta')->state(fn (Review $r) => $r->published_at?->format('d/m/Y').' · '.$r->author_name.($r->booking ? ' · '.$r->booking->reference : ''))->color('gray')->size('sm'),
                    Tables\Columns\TextColumn::make('m_body')->state(fn (Review $r) => $r->body)->wrap()->description(fn (Review $r) => $r->title, position: 'above'),
                    Tables\Columns\TextColumn::make('m_reply')->state(fn (Review $r) => $r->reply_status ? __('partner.review_reply_status.'.$r->reply_status) : __('partner.review_no_reply'))->badge()
                        ->color(fn (Review $r) => static::replyColor($r->reply_status))
                        ->description(fn (Review $r) => $r->reply_draft_status ? __('partner.review_draft_'.$r->reply_draft_status) : null),
                ])->space(2)->hiddenFrom('md'),
                // Ordinateur : tableau classique.
                Tables\Columns\TextColumn::make('published_at')->label(__('partner.review_date'))->date('d/m/Y')->sortable()->visibleFrom('md'),
                Tables\Columns\TextColumn::make('rating')->label(__('admin.rating'))->formatStateUsing(fn ($state) => static::stars($state))->sortable()->visibleFrom('md')
                    ->description(fn (Review $r) => static::criteriaLine($r->criteriaScores()))->wrap(),
                Tables\Columns\TextColumn::make('author_name')->label(__('partner.customer'))->description(fn (Review $r) => $r->booking?->reference)->visibleFrom('md'),
                Tables\Columns\TextColumn::make('body')->label(__('partner.review'))->limit(100)->wrap()->description(fn (Review $r) => $r->title)->visibleFrom('md'),
                Tables\Columns\TextColumn::make('reply_status')->label(__('partner.review_reply'))->badge()->placeholder(__('partner.review_no_reply'))->visibleFrom('md')
                    ->formatStateUsing(fn ($state) => __('partner.review_reply_status.'.$state))
                    ->description(fn (Review $r) => $r->reply_draft_status ? __('partner.review_draft_'.$r->reply_draft_status) : null)
                    ->color(fn ($state) => static::replyColor($state)),
            ])
            ->description(fn () => static::averagesLine())
            ->actions([
                Tables\Actions\Action::make('edit_reply')->label(__('partner.review_edit_reply'))->icon('heroicon-o-pencil-square')
                    ->visible(fn (Review $r) => $r->canEditReply())
                    ->modalHeading(__('partner.review_edit_reply'))
                    ->fillForm(fn (Review $r) => ['reply' => $r->replyUnderReview() ?? $r->reply])
                    ->form([
                        Placeholder::make('review')->label(__('partner.review'))->content(fn (Review $r) => new HtmlString(e(str_repeat('★', (int) $r->rating)).' — '.e($r->body))),
                        Textarea::make('reply')->label(__('partner.review_reply'))->rows(5)->required()->minLength(Review::MIN_BODY)->maxLength(2000)
                            ->helperText(fn (Review $r) => __($r->replyPublished() ? 'partner.review_edit_reply_hint' : 'partner.review_reply_hint', ['min' => Review::MIN_BODY])),
                    ])
                    ->action(function (Review $r, array $data): void {
                        try {
                            app(ReviewService::class)->reply($r, auth()->user(), $data['reply']);
                            Notification::make()->title(__($r->hasPendingDraft() ? 'partner.review_edit_reply_sent' : 'partner.review_reply_sent'))->success()->send();
                        } catch (BookingException $e) {
                            Notification::make()->title(__('partner.action_failed'))->body($e->getMessage())->danger()->send();
                        }
                    }),
                Tables\Actions\Action::make('reply')->label(__('partner.review_reply_action'))->icon('heroicon-o-chat-bubble-left-ellipsis')
                    ->visible(fn (Review $r) => $r->canReply())
                    ->modalHeading(__('partner.review_reply_action'))
                    ->form([
                        Placeholder::make('review')->label(__('partner.review'))->content(fn (Review $r) => new HtmlString(e(str_repeat('★', (int) $r->rating)).' — '.e($r->body))),
                        Textarea::make('reply')->label(__('partner.review_reply'))->rows(5)->required()->minLength(Review::MIN_BODY)->maxLength(2000)
                            ->helperText(__('partner.review_reply_hint', ['min' => Review::MIN_BODY])),
                    ])
                    ->action(function (Review $r, array $data): void {
                        try {
                            app(ReviewService::class)->reply($r, auth()->user(), $data['reply']);
                            Notification::make()->title(__('partner.review_reply_sent'))->success()->send();
                        } catch (BookingException $e) {
                            Notification::make()->title(__('partner.action_failed'))->body($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }

    public const SCORE_ICONS = [1 => '🙁', 2 => '😐', 3 => '😊'];

    public static function stars(mixed $rating): string
    {
        return str_repeat('★', (int) $rating).str_repeat('☆', 5 - (int) $rating);
    }

    public static function replyColor(?string $status): string
    {
        return match ($status) {
            'published' => 'success', 'pending' => 'warning', null => 'gray', default => 'danger'
        };
    }

    /** « Accueil 😊 · Propreté 😐 … » pour un avis ; vide si le client n'a noté aucun critère. */
    public static function criteriaLine(array $scores): ?string
    {
        if ($scores === []) {
            return null;
        }
        $parts = [];
        foreach ($scores as $k => $v) {
            $parts[] = __('ui.review.criteria.'.$k).' '.(self::SCORE_ICONS[max(1, min(3, $v))] ?? '');
        }

        return implode(' · ', $parts);
    }

    /** Moyennes de l'établissement : note globale puis chaque critère (sur 3), sur les avis publiés. */
    public static function averagesLine(): ?string
    {
        $reviews = static::getEloquentQuery()->get(['rating', 'criteria']);
        if ($reviews->isEmpty()) {
            return null;
        }
        $sums = [];
        foreach ($reviews as $r) {
            foreach ($r->criteriaScores() as $k => $v) {
                $sums[$k][] = $v;
            }
        }
        $parts = [__('partner.review_avg_rating', ['avg' => number_format($reviews->avg('rating'), 1, ',', ' ')])];
        foreach (Review::CRITERIA as $k) {
            if (! empty($sums[$k])) {
                $avg = array_sum($sums[$k]) / count($sums[$k]);
                $parts[] = __('ui.review.criteria.'.$k).' '.(self::SCORE_ICONS[(int) round($avg)] ?? '').' '.number_format($avg, 1, ',', ' ').'/3';
            }
        }

        return __('partner.review_averages', ['n' => $reviews->count()]).' — '.implode(' · ', $parts);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListReviews::route('/')];
    }
}
