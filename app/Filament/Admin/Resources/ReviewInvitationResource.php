<?php

namespace App\Filament\Admin\Resources;

use App\Domain\Review\ReviewService;
use App\Filament\Admin\Resources\ReviewInvitationResource\Pages;
use App\Jobs\SendBookingNotification;
use App\Models\Booking;
use App\Models\Spa;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

/**
 * Suivi des invitations à laisser un avis, en lecture seule. La ligne de base est la réservation terminée :
 * c'est la seule façon de montrer aussi les cas « non applicable » (pas d'e-mail client) et « pas encore due ».
 * « Envoyée » signifie livraison SMTP réellement réussie (trace booking_notifications), jamais une simple mise en file.
 */
class ReviewInvitationResource extends Resource
{
    protected static ?string $model = Booking::class;

    protected static ?string $slug = 'review-invitations';

    protected static ?string $navigationIcon = 'heroicon-o-envelope-open';

    protected static ?int $navigationSort = 51;

    public const STATUSES = ['not_due', 'queued', 'sent', 'send_failed', 'invited_untracked', 'pending', 'published', 'rejected', 'not_applicable', 'expired'];

    public static function getModelLabel(): string
    {
        return __('admin.ri_invitation');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.ri_invitations');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $n = static::getEloquentQuery()->where('invitation_status', 'send_failed')->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /** Réservations terminées enrichies d'un statut d'invitation dérivé (jamais stocké), filtrable et triable en SQL. */
    public static function getEloquentQuery(): Builder
    {
        $today = CarbonImmutable::now(config('hl.timezone'))->startOfDay();
        $notApplicable = DB::table('booking_events')->selectRaw('1')->whereColumn('booking_events.booking_id', 'b.id')->where('type', ReviewService::EVENT_NOT_APPLICABLE);

        $inner = DB::table('bookings as b')
            ->leftJoin('reviews as r', 'r.booking_id', '=', 'b.id')
            ->leftJoin('booking_notifications as n', function ($join) {
                $join->on('n.booking_id', '=', 'b.id')->where('n.event', SendBookingNotification::REVIEW_INVITE)->where('n.audience', 'client');
            })
            ->where('b.status', 'completed')
            ->select('b.*')
            ->selectRaw('r.id as review_id, r.status as review_status, r.invited_at as invited_at, r.submitted_at as submitted_at')
            ->selectRaw('n.status as notif_status, n.sent_at as notif_sent_at, n.failed_at as notif_failed_at, n.error as notif_error')
            ->selectRaw("CASE
                WHEN EXISTS ({$notApplicable->toSql()}) THEN 'not_applicable'
                WHEN r.status IN ('pending', 'published', 'rejected') THEN r.status
                WHEN r.status = 'invited' AND n.status = 'sent' THEN 'sent'
                WHEN r.status = 'invited' AND n.status = 'failed' THEN 'send_failed'
                WHEN r.status = 'invited' AND n.id IS NOT NULL THEN 'queued'
                WHEN r.status = 'invited' THEN 'invited_untracked'
                WHEN b.end_at >= ? THEN 'not_due'
                ELSE 'expired' END as invitation_status", [...$notApplicable->getBindings(), $today->subDays(ReviewService::INVITE_MAX_DAYS)->utc()->toDateTimeString()]);

        return Booking::query()->fromSub($inner, 'bookings')->with(['spa', 'review']);
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'sent', 'published' => 'success',
            'queued', 'not_due', 'invited_untracked' => 'gray',
            'pending' => 'warning',
            'send_failed', 'rejected' => 'danger',
            default => 'info', // not_applicable, expired
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('end_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('reference')->label(__('partner.reference'))->weight('bold')->searchable()
                    ->url(fn (Booking $b) => BookingResource::getUrl('view', ['record' => $b->id])),
                Tables\Columns\TextColumn::make('spa.name')->label(__('admin.spa'))->sortable(),
                Tables\Columns\TextColumn::make('first_name')->label(__('admin.ri_client'))
                    ->getStateUsing(fn (Booking $b) => trim($b->first_name.' '.$b->last_name))
                    ->description(fn (Booking $b) => filter_var(trim((string) $b->email), FILTER_VALIDATE_EMAIL)
                        ? $b->email
                        : new HtmlString('<span class="font-medium text-danger-600 dark:text-danger-400">— '.e(__('admin.ri_no_email')).'</span>'))
                    ->color(fn (Booking $b) => filter_var(trim((string) $b->email), FILTER_VALIDATE_EMAIL) ? null : 'danger'),
                Tables\Columns\TextColumn::make('end_at')->label(__('admin.ri_end_at'))->dateTime('d/m/Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('invited_at')->label(__('admin.ri_invited_at'))->dateTime('d/m/Y H:i')->sortable()->placeholder('—'),
                Tables\Columns\TextColumn::make('notif_sent_at')->label(__('admin.ri_sent_at'))->dateTime('d/m/Y H:i')->sortable()->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('submitted_at')->label(__('admin.review_submitted_at'))->dateTime('d/m/Y H:i')->sortable()->placeholder('—'),
                Tables\Columns\TextColumn::make('invitation_status')->label(__('partner.status'))->badge()->sortable()
                    ->formatStateUsing(fn ($state) => __("admin.ri_status.$state"))
                    ->color(fn ($state) => static::statusColor((string) $state))
                    ->description(fn (Booking $b) => match ($b->invitation_status) {
                        'not_applicable' => __('admin.ri_reason_no_email'),
                        'send_failed' => $b->notif_error ? mb_substr($b->notif_error, 0, 80) : null,
                        'not_due' => $b->end_at->gte(CarbonImmutable::now(config('hl.timezone'))->startOfDay()) ? __('admin.ri_reason_not_due') : __('admin.ri_reason_due_next_run'),
                        'expired' => __('admin.ri_reason_expired', ['days' => ReviewService::INVITE_MAX_DAYS]),
                        'invited_untracked' => __('admin.ri_reason_untracked'),
                        default => null,
                    })
                    ->tooltip(fn (Booking $b) => $b->invitation_status === 'send_failed' ? $b->notif_error : null),
                Tables\Columns\TextColumn::make('review_id')->label(__('admin.review'))->placeholder('—')
                    ->formatStateUsing(fn () => __('admin.ri_open_review'))
                    ->url(fn (Booking $b) => $b->review_id && $b->review_status !== 'invited' ? ReviewResource::getUrl('view', ['record' => $b->review_id]) : null)
                    ->visible(fn () => true)
                    ->getStateUsing(fn (Booking $b) => $b->review_id && $b->review_status !== 'invited' ? $b->review_id : null),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('spa_id')->label(__('admin.spa'))->options(fn () => Spa::orderBy('name')->pluck('name', 'id')->all())->searchable(),
                Tables\Filters\SelectFilter::make('invitation_status')->label(__('partner.status'))->multiple()
                    ->options(fn () => collect(self::STATUSES)->mapWithKeys(fn ($s) => [$s => __("admin.ri_status.$s")])->all())
                    ->query(fn (Builder $query, array $data) => filled($data['values'] ?? null) ? $query->whereIn('invitation_status', $data['values']) : $query),
                Tables\Filters\Filter::make('end_at')->label(__('admin.ri_end_at'))
                    ->form([DatePicker::make('from')->label(__('admin.ri_end_at').' — '.__('admin.ri_from')), DatePicker::make('until')->label(__('admin.ri_end_at').' — '.__('admin.ri_until'))])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('end_at', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('end_at', '<=', $d)))
                    ->indicateUsing(fn (array $data) => array_filter([$data['from'] ?? null ? __('admin.ri_from').' '.$data['from'] : null, $data['until'] ?? null ? __('admin.ri_until').' '.$data['until'] : null])),
                Tables\Filters\Filter::make('invited_at')->label(__('admin.ri_invited_at'))
                    ->form([DatePicker::make('from')->label(__('admin.ri_invited_at').' — '.__('admin.ri_from')), DatePicker::make('until')->label(__('admin.ri_invited_at').' — '.__('admin.ri_until'))])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('invited_at', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('invited_at', '<=', $d)))
                    ->indicateUsing(fn (array $data) => array_filter([$data['from'] ?? null ? __('admin.ri_invited_at').' ≥ '.$data['from'] : null, $data['until'] ?? null ? __('admin.ri_invited_at').' ≤ '.$data['until'] : null])),
                Tables\Filters\TernaryFilter::make('has_email')->label(__('admin.ri_has_email'))
                    ->queries(
                        true: fn (Builder $query) => $query->where('email', 'like', '%_@_%.__%'),
                        false: fn (Builder $query) => $query->whereRaw('(email IS NULL OR email NOT LIKE ?)', ['%_@_%.__%']),
                    ),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListReviewInvitations::route('/')];
    }
}
