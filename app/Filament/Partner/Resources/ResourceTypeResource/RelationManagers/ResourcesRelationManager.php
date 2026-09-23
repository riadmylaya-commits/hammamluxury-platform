<?php

namespace App\Filament\Partner\Resources\ResourceTypeResource\RelationManagers;

use App\Models\ResourceType;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ResourcesRelationManager extends RelationManager
{
    protected static string $relationship = 'resources';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('partner.resources');
    }

    public function form(Form $form): Form
    {
        /** @var ResourceType $type */
        $type = $this->getOwnerRecord();
        $isPool = $type->allocation_mode === 'pool';

        return $form->schema([
            Forms\Components\TextInput::make('name')->label(__('partner.resource_name'))->required()->maxLength(190)->placeholder($isPool ? 'Hammam' : 'Cabine 1 / Fatima'),
            Forms\Components\TextInput::make('capacity')->label(__('partner.capacity'))->numeric()->minValue(1)->default($isPool ? 6 : 1)->required()->helperText($isPool ? __('partner.capacity_pool_help') : __('partner.capacity_unit_help')),
            Forms\Components\TextInput::make('min_party')->label(__('partner.min_party'))->numeric()->minValue(1)->default(1)->required(),
            Forms\Components\TextInput::make('max_party')->label(__('partner.max_party'))->numeric()->minValue(1)->default($isPool ? 6 : 1)->required(),
            Forms\Components\Select::make('status')->label(__('partner.status'))->options(['active' => __('partner.active'), 'inactive' => __('partner.inactive')])->default('active')->required(),
            Forms\Components\Hidden::make('spa_id')->default(fn () => Filament::getTenant()->id),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->reorderable('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label(__('partner.resource_name')),
                Tables\Columns\TextColumn::make('capacity')->label(__('partner.capacity')),
                Tables\Columns\TextColumn::make('max_party')->label(__('partner.max_party')),
                Tables\Columns\IconColumn::make('status')->label(__('partner.status'))->getStateUsing(fn ($record) => $record->status === 'active')->boolean(),
            ])
            ->headerActions([Tables\Actions\CreateAction::make()->label(__('partner.add_resource'))])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()])
            ->emptyStateHeading(__('partner.no_resources'));
    }
}
