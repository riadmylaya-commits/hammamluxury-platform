<?php

namespace App\Filament\Partner\Resources\TreatmentResource\RelationManagers;

use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ExtrasRelationManager extends RelationManager
{
    protected static string $relationship = 'extras';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('partner.extras');
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name_fr')->label(__('partner.name_fr'))->required()->maxLength(190),
            Forms\Components\TextInput::make('name_en')->label(__('partner.name_en'))->maxLength(190),
            Forms\Components\TextInput::make('price')->label(__('partner.extra_price'))->numeric()->minValue(0)->suffix(config('hl.currency'))->required()->default(0),
            Forms\Components\TextInput::make('extra_min')->label(__('partner.extra_min'))->numeric()->minValue(0)->step(5)->suffix('min')->default(0)->helperText(__('partner.extra_min_help')),
            Forms\Components\Toggle::make('per_person')->label(__('partner.per_person'))->default(true)->helperText(__('partner.per_person_help')),
            Forms\Components\TextInput::make('max_qty')->label(__('partner.max_qty'))->numeric()->minValue(1)->default(1),
            Forms\Components\Hidden::make('spa_id')->default(fn () => Filament::getTenant()->id),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name_fr')->label(__('partner.extra')),
                Tables\Columns\TextColumn::make('price')->label(__('partner.extra_price'))->money(config('hl.currency'), locale: 'fr'),
                Tables\Columns\TextColumn::make('extra_min')->label(__('partner.extra_min'))->suffix(' min'),
                Tables\Columns\IconColumn::make('per_person')->label(__('partner.per_person'))->boolean(),
                Tables\Columns\TextColumn::make('max_qty')->label(__('partner.max_qty')),
            ])
            ->headerActions([Tables\Actions\CreateAction::make()->label(__('partner.add_extra'))])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()])
            ->description(__('partner.extras_intro'))
            ->emptyStateHeading(__('partner.no_extras'));
    }
}
