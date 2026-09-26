<?php

namespace App\Filament\Admin\Resources;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/** Base des référentiels (villes, expériences, équipements) : slug stable, libellés FR/EN, ordre, activation. */
abstract class ReferenceResource extends Resource
{
    protected static ?int $navigationSort = 60;

    protected static bool $hasPopular = false;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.group_reference');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name_fr')->label(__('partner.name_fr'))->required()->maxLength(120)->live(onBlur: true)
                ->afterStateUpdated(fn (Forms\Set $set, ?string $state, $record) => $record ?: $set('slug', Str::slug((string) $state))),
            Forms\Components\TextInput::make('name_en')->label(__('partner.name_en'))->maxLength(120),
            Forms\Components\TextInput::make('slug')->label('Slug')->required()->maxLength(80)->alphaDash()->unique(ignoreRecord: true)
                ->helperText(__('admin.slug_help'))->disabled(fn ($record) => $record !== null)->dehydrated(),
            Forms\Components\TextInput::make('sort_order')->label(__('admin.sort_order'))->numeric()->default(0),
            Forms\Components\Toggle::make('is_active')->label(__('admin.active'))->default(true),
            Forms\Components\Toggle::make('is_popular')->label(__('admin.popular'))->visible(static::$hasPopular),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('name_fr')->label(__('partner.name_fr'))->searchable(),
                Tables\Columns\TextColumn::make('name_en')->label(__('partner.name_en'))->searchable(),
                Tables\Columns\TextColumn::make('slug')->label('Slug')->color('gray'),
                Tables\Columns\TextColumn::make('spas_count')->label(__('admin.spas'))->counts('spas'),
                Tables\Columns\IconColumn::make('is_popular')->label(__('admin.popular'))->boolean()->visible(static::$hasPopular),
                Tables\Columns\IconColumn::make('is_active')->label(__('admin.active'))->boolean(),
            ])
            ->actions([Tables\Actions\EditAction::make()]);
    }
}
