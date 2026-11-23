<?php

namespace App\Filament\Admin\Resources;

use App\Domain\Support\SupportService;
use App\Filament\Admin\Resources\SupportRequestResource\Pages;
use App\Models\SupportRequest;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Demandes d'assistance écrites des clients et des partenaires : suivi Nouveau / En cours / Résolu, réponse par e-mail, historique. */
class SupportRequestResource extends Resource
{
    protected static ?string $model = SupportRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-lifebuoy';

    protected static ?int $navigationSort = 37;

    public static function getModelLabel(): string
    {
        return __('support.admin_single');
    }

    public static function getPluralModelLabel(): string
    {
        return __('support.admin_nav');
    }

    public static function getNavigationBadge(): ?string
    {
        $n = SupportRequest::where('status', 'new')->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
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
    public static function supportActions(string $class = Tables\Actions\Action::class): array
    {
        return [
            $class::make('reply')->label(__('support.reply'))->icon('heroicon-o-paper-airplane')->color('primary')
                ->form([Textarea::make('body')->label(__('support.reply_body'))->required()->rows(6)->minLength(5)->maxLength(3000)])
                ->action(function (SupportRequest $r, array $data) {
                    app(SupportService::class)->reply($r, trim($data['body']), auth()->user());
                    Notification::make()->title(__('support.reply_sent'))->success()->send();
                }),
            $class::make('setStatus')->label(__('support.set_status'))->icon('heroicon-o-arrow-path')->color('gray')
                ->fillForm(fn (SupportRequest $r) => ['status' => $r->status])
                ->form([Select::make('status')->label(__('support.status'))->options(__('support.statuses'))->required()->native(false)])
                ->action(function (SupportRequest $r, array $data) {
                    app(SupportService::class)->setStatus($r, $data['status']);
                    Notification::make()->title(__('support.status_updated'))->success()->send();
                }),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('spa'))
            ->columns([
                Tables\Columns\TextColumn::make('status')->label(__('support.status'))->badge()
                    ->formatStateUsing(fn ($state) => __('support.statuses')[$state] ?? $state)
                    ->color(fn ($state) => SupportRequest::statusColor((string) $state)),
                Tables\Columns\TextColumn::make('subject')->label(__('support.subject'))->searchable()->weight('bold')->wrap()
                    ->description(fn (SupportRequest $r) => $r->spa?->name ?? $r->name),
                Tables\Columns\TextColumn::make('source')->label(__('support.from'))->badge()->color(fn ($state) => $state === 'partner' ? 'info' : 'gray')
                    ->formatStateUsing(fn ($state) => __('support.sources')[$state] ?? $state)->visibleFrom('lg'),
                Tables\Columns\TextColumn::make('created_at')->label(__('support.received_at'))->dateTime('d/m/Y H:i')->sortable()->visibleFrom('md'),
                Tables\Columns\TextColumn::make('reference')->label(__('support.reference'))->searchable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('email')->searchable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label(__('support.status'))->options(__('support.statuses'))->multiple(),
                Tables\Filters\SelectFilter::make('source')->label(__('support.from'))->options(__('support.sources')),
                Tables\Filters\SelectFilter::make('spa_id')->label(__('support.spa'))->relationship('spa', 'name')->searchable(),
            ])
            ->actions([Tables\Actions\ActionGroup::make([Tables\Actions\ViewAction::make(), ...self::supportActions()])
                ->label(__('support.actions'))->extraAttributes(['aria-label' => __('support.actions')])]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make()->columns(2)->schema([
                TextEntry::make('reference')->label(__('support.reference')),
                TextEntry::make('status')->label(__('support.status'))->badge()
                    ->formatStateUsing(fn ($state) => __('support.statuses')[$state] ?? $state)->color(fn ($state) => SupportRequest::statusColor((string) $state)),
                TextEntry::make('name')->label(__('support.from'))->formatStateUsing(fn ($state, SupportRequest $r) => $state.' · '.$r->email),
                TextEntry::make('source')->label('')->formatStateUsing(fn ($state) => __('support.sources')[$state] ?? $state),
                TextEntry::make('spa.name')->label(__('support.spa'))->placeholder('—')
                    ->url(fn (SupportRequest $r) => $r->spa_id ? SpaResource::getUrl('edit', ['record' => $r->spa_id]) : null),
                TextEntry::make('created_at')->label(__('support.received_at'))->dateTime('d/m/Y H:i'),
                TextEntry::make('subject')->label(__('support.subject'))->columnSpanFull(),
                TextEntry::make('body')->label(__('support.message'))->columnSpanFull()->formatStateUsing(fn ($state) => nl2br(e($state)))->html(),
            ]),
            Section::make(__('support.history'))->schema([
                RepeatableEntry::make('messages')->label('')->placeholder(__('support.no_reply'))->columns(2)->schema([
                    TextEntry::make('sender')->label('')->badge()->color(fn ($state) => $state === 'admin' ? 'warning' : 'gray')
                        ->formatStateUsing(fn ($state) => __('support.senders')[$state] ?? $state),
                    TextEntry::make('created_at')->label('')->dateTime('d/m/Y H:i'),
                    TextEntry::make('body')->label('')->columnSpanFull()->formatStateUsing(fn ($state) => nl2br(e($state)))->html(),
                ]),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSupportRequests::route('/'),
            'view' => Pages\ViewSupportRequest::route('/{record}'),
        ];
    }
}
