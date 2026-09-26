<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\ActivityLogResource\Pages;
use App\Models\ActivityLog;
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

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'subject']))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label(__('admin.date'))->dateTime('d/m/Y H:i'),
                Tables\Columns\TextColumn::make('panel')->label(__('admin.panel'))->badge(),
                Tables\Columns\TextColumn::make('action')->label(__('admin.action'))->formatStateUsing(fn (string $state) => __('admin.log_action')[$state] ?? $state)->searchable(),
                Tables\Columns\TextColumn::make('user.name')->label(__('admin.user'))->default('—')->searchable(),
                Tables\Columns\TextColumn::make('subject')->label(__('admin.subject'))
                    ->getStateUsing(fn (ActivityLog $l) => $l->subject ? class_basename($l->subject_type).' #'.$l->subject_id.(isset($l->subject->name) ? ' · '.$l->subject->name : '') : '—'),
                Tables\Columns\TextColumn::make('properties')->label(__('admin.details'))
                    ->getStateUsing(fn (ActivityLog $l) => collect($l->properties ?? [])->map(fn ($v, $k) => "$k: ".(is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE)))->implode(' · '))->limit(80)->wrap(),
                Tables\Columns\TextColumn::make('ip')->label('IP')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('panel')->options(['admin' => 'admin', 'partner' => 'partner', 'site' => 'site', 'system' => 'system']),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListActivityLogs::route('/')];
    }
}
