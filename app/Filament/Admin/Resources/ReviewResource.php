<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\ReviewResource\Pages;
use App\Models\Review;
use App\Models\Spa;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Modération des avis ; un avis lié à une réservation terminée est marqué « vérifié ». */
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

    public static function getNavigationBadge(): ?string
    {
        $n = Review::where('status', 'pending')->count();

        return $n ? (string) $n : null;
    }

    public static function table(Table $table): Table
    {
        $moderate = function (Review $r, string $status): void {
            $r->update(['status' => $status]);
            self::recomputeRating($r->spa);
        };

        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('spa'))
            ->columns([
                Tables\Columns\TextColumn::make('spa.name')->label(__('admin.spa'))->searchable(),
                Tables\Columns\TextColumn::make('author_name')->label(__('admin.author')),
                Tables\Columns\TextColumn::make('rating')->label(__('admin.rating'))->formatStateUsing(fn ($state) => str_repeat('★', (int) $state).str_repeat('☆', 5 - (int) $state)),
                Tables\Columns\TextColumn::make('body')->label(__('admin.review'))->limit(80)->wrap(),
                Tables\Columns\IconColumn::make('booking_id')->label(__('admin.verified'))->boolean()->getStateUsing(fn (Review $r) => $r->booking_id !== null),
                Tables\Columns\TextColumn::make('status')->label(__('partner.status'))->badge()
                    ->formatStateUsing(fn ($state) => __("admin.r_$state"))
                    ->color(fn ($state) => match ($state) {
                        'published' => 'success', 'pending' => 'warning', default => 'danger'
                    }),
            ])
            ->filters([Tables\Filters\SelectFilter::make('status')->options(['pending' => __('admin.r_pending'), 'published' => __('admin.r_published'), 'rejected' => __('admin.r_rejected')])->default('pending')])
            ->actions([
                Tables\Actions\Action::make('publish')->label(__('admin.publish'))->icon('heroicon-o-check')->color('success')
                    ->visible(fn (Review $r) => $r->status !== 'published')->action(fn (Review $r) => $moderate($r, 'published')),
                Tables\Actions\Action::make('reject')->label(__('admin.reject'))->icon('heroicon-o-x-mark')->color('danger')
                    ->visible(fn (Review $r) => $r->status !== 'rejected')->requiresConfirmation()->action(fn (Review $r) => $moderate($r, 'rejected')),
            ]);
    }

    public static function recomputeRating(Spa $spa): void
    {
        $q = $spa->reviews()->where('status', 'published');
        $spa->update(['rating' => $q->exists() ? round((float) $q->avg('rating'), 2) : null, 'reviews_count' => $q->count()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListReviews::route('/')];
    }
}
