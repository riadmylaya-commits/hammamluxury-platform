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
                Tables\Columns\TextColumn::make('published_at')->label(__('partner.review_date'))->date('d/m/Y')->sortable(),
                Tables\Columns\TextColumn::make('rating')->label(__('admin.rating'))->formatStateUsing(fn ($state) => str_repeat('★', (int) $state).str_repeat('☆', 5 - (int) $state))->sortable(),
                Tables\Columns\TextColumn::make('author_name')->label(__('partner.customer'))->description(fn (Review $r) => $r->booking?->reference),
                Tables\Columns\TextColumn::make('body')->label(__('partner.review'))->limit(100)->wrap()->description(fn (Review $r) => $r->title),
                Tables\Columns\TextColumn::make('reply_status')->label(__('partner.review_reply'))->badge()->placeholder(__('partner.review_no_reply'))
                    ->formatStateUsing(fn ($state) => __('partner.review_reply_status.'.$state))
                    ->color(fn ($state) => match ($state) {
                        'published' => 'success', 'pending' => 'warning', default => 'danger'
                    }),
            ])
            ->actions([
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

    public static function getPages(): array
    {
        return ['index' => Pages\ListReviews::route('/')];
    }
}
