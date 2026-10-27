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
                // Mobile : une carte par avis (une colonne de type texte, pas un composant de mise en page,
                // afin que l'en-tête du tableau reste présent sur ordinateur).
                Tables\Columns\TextColumn::make('m_card')->label('')->state(fn (Review $r) => static::mobileCard($r))->html()->wrap()->hiddenFrom('md'),
                // Ordinateur : tableau classique.
                Tables\Columns\TextColumn::make('published_at')->label(__('partner.review_date'))->date('d/m/Y')->sortable()->visibleFrom('md'),
                Tables\Columns\TextColumn::make('rating')->label(__('admin.rating'))->formatStateUsing(fn ($state) => static::stars($state))->sortable()->visibleFrom('md')
                    ->description(fn (Review $r) => static::criteriaDetails($r))->wrap(),
                Tables\Columns\TextColumn::make('author_name')->label(__('partner.customer'))->description(fn (Review $r) => $r->booking?->reference)->visibleFrom('md'),
                Tables\Columns\TextColumn::make('body')->label(__('partner.review'))->limit(100)->wrap()->description(fn (Review $r) => $r->title)->visibleFrom('md'),
                Tables\Columns\TextColumn::make('reply_status')->label(__('partner.review_reply'))->badge()->placeholder(__('partner.review_no_reply'))->visibleFrom('md')
                    ->formatStateUsing(fn ($state) => __('partner.review_reply_status.'.$state))
                    ->description(fn (Review $r) => $r->reply_draft_status ? __('partner.review_draft_'.$r->reply_draft_status) : null)
                    ->color(fn ($state) => static::replyColor($state)),
            ])
            ->description(fn () => static::averagesLine())
            ->actions([
                // Ordinateur : boutons explicites ; mobile : un seul bouton « Réponse » ouvrant les mêmes actions (gain de largeur).
                static::editReplyAction('edit_reply')->extraAttributes(['class' => 'hidden md:flex']),
                static::replyAction('reply')->extraAttributes(['class' => 'hidden md:flex']),
                Tables\Actions\ActionGroup::make([static::editReplyAction('edit_reply_mobile'), static::replyAction('reply_mobile')])
                    ->label(__('partner.review_actions'))->icon('heroicon-m-chat-bubble-left-ellipsis')->button()->size('sm')->color('gray')
                    ->extraAttributes(['class' => 'md:hidden']),
            ]);
    }

    public static function editReplyAction(string $name): Tables\Actions\Action
    {
        return Tables\Actions\Action::make($name)->label(__('partner.review_edit_reply'))->icon('heroicon-o-pencil-square')
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
            });
    }

    public static function replyAction(string $name): Tables\Actions\Action
    {
        return Tables\Actions\Action::make($name)->label(__('partner.review_reply_action'))->icon('heroicon-o-chat-bubble-left-ellipsis')
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
            });
    }

    public static function stars(mixed $rating): string
    {
        return str_repeat('★', (int) $rating).str_repeat('☆', 5 - (int) $rating);
    }

    /** Carte mobile : note, critères, date · client · référence, titre, commentaire, statut de la réponse. */
    public static function mobileCard(Review $r): HtmlString
    {
        $muted = 'style="color:#6b7280;font-size:.8rem"';
        $badge = sprintf('<span class="fi-badge fi-color-%s" style="display:inline-block;border-radius:.375rem;padding:.1rem .5rem;font-size:.75rem;font-weight:600;%s">%s</span>',
            static::replyColor($r->reply_status),
            match (static::replyColor($r->reply_status)) {
                'success' => 'background:#dcfce7;color:#166534', 'warning' => 'background:#fef3c7;color:#92400e', 'danger' => 'background:#fee2e2;color:#991b1b', default => 'background:#f3f4f6;color:#374151'
            },
            e($r->reply_status ? __('partner.review_reply_status.'.$r->reply_status) : __('partner.review_no_reply')));
        $parts = [
            '<div style="font-size:1.05rem;font-weight:700">'.e(static::stars($r->rating)).'</div>',
            ($c = static::criteriaDetails($r)) ? $c->toHtml() : '',
            '<div '.$muted.'>'.e(trim($r->published_at?->format('d/m/Y').' · '.$r->author_name.($r->booking ? ' · '.$r->booking->reference : ''))).'</div>',
            $r->title ? '<div style="font-weight:600;margin-top:.35rem">'.e($r->title).'</div>' : '',
            '<div style="margin-top:.2rem;white-space:normal">'.e($r->body).'</div>',
            '<div style="margin-top:.45rem">'.$badge.($r->reply_draft_status ? ' <span '.$muted.'>'.e(__('partner.review_draft_'.$r->reply_draft_status)).'</span>' : '').'</div>',
        ];

        return new HtmlString('<div style="display:flex;flex-direction:column;gap:.15rem;max-width:100%">'.implode('', array_filter($parts)).'</div>');
    }

    public static function replyColor(?string $status): string
    {
        return match ($status) {
            'published' => 'success', 'pending' => 'warning', null => 'gray', default => 'danger'
        };
    }

    /** Détail des critères replié par défaut, dans l'échelle d'origine de l'avis (3 ou 5), pour ne pas alourdir la liste. */
    public static function criteriaDetails(Review $r): ?HtmlString
    {
        if (($line = $r->criteriaSummary()) === null) {
            return null;
        }

        $items = implode('', array_map(fn (string $part) => '<div>'.e($part).'</div>', explode(' · ', $line)));
        $legacy = $r->hasLegacyCriteria() ? '<div style="font-style:italic;margin-top:.2rem">'.e(__('partner.review_legacy_scale')).'</div>' : '';

        return new HtmlString('<details style="font-size:.8rem;color:#6b7280;white-space:normal;max-width:16rem"><summary style="cursor:pointer">'.e(__('partner.review_criteria_toggle')).'</summary><div style="margin-top:.25rem;line-height:1.35">'.$items.$legacy.'</div></details>');
    }

    /**
     * Moyennes des avis publiés : note globale (tous les avis) puis une ligne par critère (x,x / 5) avec barre de progression,
     * calculée uniquement sur les avis notés sur 5 ; les avis sur l'ancienne échelle (sur 3) sont comptés à part, jamais convertis.
     */
    public static function averagesLine(): ?HtmlString
    {
        $reviews = static::getEloquentQuery()->get(['rating', 'criteria', 'criteria_scale']);
        if ($reviews->isEmpty()) {
            return null;
        }
        $sums = [];
        $rated = 0;
        $legacy = 0;
        foreach ($reviews as $r) {
            if (($scores = $r->criteriaScores()) === []) {
                continue;
            }
            if ($r->hasLegacyCriteria()) {
                $legacy++;

                continue;
            }
            $rated++;
            foreach ($scores as $k => $v) {
                $sums[$k][] = $v;
            }
        }
        $scale = Review::CRITERIA_SCALE;
        $rows = '<div style="font-size:.8rem;color:#6b7280;margin-top:.2rem">'.e(__('partner.review_criteria_averages', ['n' => $rated])).'</div>';
        foreach (Review::CRITERIA as $k) {
            $avg = empty($sums[$k]) ? null : array_sum($sums[$k]) / count($sums[$k]);
            $pct = $avg === null ? 0 : (int) round($avg / $scale * 100);
            $rows .= '<div style="display:flex;align-items:center;gap:.6rem;font-size:.85rem"><span style="flex:0 0 11rem">'.e(__('ui.review.criteria.'.$k)).'</span>'
                .'<span style="flex:1;height:.4rem;background:#e5e7eb;border-radius:999px;overflow:hidden"><span style="display:block;width:'.$pct.'%;height:100%;background:#b8743a"></span></span>'
                .'<span style="flex:0 0 4rem;text-align:right;font-weight:600">'.($avg === null ? '—' : e(number_format($avg, 1, ',', ' ').' / '.$scale)).'</span></div>';
        }
        if ($legacy > 0) {
            $rows .= '<div style="font-size:.8rem;color:#6b7280;font-style:italic">'.e(__('partner.review_legacy_excluded', ['n' => $legacy])).'</div>';
        }
        $head = '<div style="font-weight:600;margin-bottom:.35rem">'.e(__('partner.review_averages', ['n' => $reviews->count()])).' — '
            .e(__('partner.review_avg_rating', ['avg' => number_format($reviews->avg('rating'), 1, ',', ' ')])).' '.e(static::stars((int) round($reviews->avg('rating')))).'</div>';

        return new HtmlString('<div style="display:flex;flex-direction:column;gap:.3rem;max-width:40rem">'.$head.$rows.'</div>');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListReviews::route('/')];
    }
}
