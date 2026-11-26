<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\ActivityLogResource\Pages;
use App\Models\ActivityLog;
use App\Models\Partner;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Journal des actions importantes (lecture seule). */
class ActivityLogResource extends Resource
{
    protected static ?string $model = ActivityLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?int $navigationSort = 90;

    public static function getModelLabel(): string
    {
        return __('admin.activity_log');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.activity_logs');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function actionLabel(string $action): string
    {
        return __('admin.log_action')[$action] ?? $action;
    }

    public static function subjectLabel(ActivityLog $l): string
    {
        if (! $l->subject) {
            return '—';
        }
        $name = $l->subject->company_name ?? $l->subject->name ?? null;

        return class_basename($l->subject_type).' #'.$l->subject_id.($name ? ' · '.$name : '');
    }

    public static function details(ActivityLog $l): string
    {
        return collect($l->properties ?? [])->map(fn ($v, $k) => "$k: ".(is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE)))->implode(' · ');
    }

    public static function mobileCard(ActivityLog $l): string
    {
        return implode('', [
            '<div><strong>'.e(self::actionLabel($l->action)).'</strong></div>',
            '<div>'.e($l->created_at->format('d/m/Y H:i')).' · '.e($l->user->name ?? $l->panel).'</div>',
            '<div>'.e(self::subjectLabel($l)).'</div>',
            '<div style="overflow-wrap:anywhere">'.e(self::details($l)).'</div>',
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'subject']))
            ->columns([
                Tables\Columns\TextColumn::make('m_card')->label('')->state(fn (ActivityLog $l) => self::mobileCard($l))->html()->wrap()->hiddenFrom('xl'),
                Tables\Columns\TextColumn::make('created_at')->visibleFrom('xl')->label(__('admin.date'))->dateTime('d/m/Y H:i'),
                Tables\Columns\TextColumn::make('panel')->visibleFrom('xl')->label(__('admin.panel'))->badge(),
                Tables\Columns\TextColumn::make('action')->visibleFrom('xl')->label(__('admin.action'))->formatStateUsing(fn (string $state) => self::actionLabel($state))->wrap()->searchable(),
                Tables\Columns\TextColumn::make('user.name')->visibleFrom('xl')->label(__('admin.user'))->default('—')->searchable(),
                Tables\Columns\TextColumn::make('subject')->visibleFrom('xl')->label(__('admin.subject'))->wrap()
                    ->getStateUsing(fn (ActivityLog $l) => self::subjectLabel($l)),
                Tables\Columns\TextColumn::make('properties')->visibleFrom('xl')->label(__('admin.details'))
                    ->getStateUsing(fn (ActivityLog $l) => self::details($l))->wrap(),
                Tables\Columns\TextColumn::make('ip')->visibleFrom('xl')->label('IP')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('panel')->options(['admin' => 'admin', 'partner' => 'partner', 'site' => 'site', 'system' => 'system']),
                Tables\Filters\SelectFilter::make('partner')->label(__('admin.partner'))
                    ->options(fn () => Partner::orderBy('company_name')->pluck('company_name', 'id')->all())
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->where('subject_type', (new Partner)->getMorphClass())->where('subject_id', $data['value'])
                        : $query),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListActivityLogs::route('/')];
    }
}
