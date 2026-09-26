<?php

namespace App\Filament\Partner\Resources;

use App\Filament\Partner\Resources\ResourceTypeResource\Pages;
use App\Filament\Partner\Resources\ResourceTypeResource\RelationManagers\ResourcesRelationManager;
use App\Models\ResourceType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/** Cabines, thérapeutes, hammam, jacuzzi… regroupés par type ; la capacité réelle vient des ressources de chaque type. */
class ResourceTypeResource extends Resource
{
    protected static ?string $model = ResourceType::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?int $navigationSort = 30;

    public static function getModelLabel(): string
    {
        return __('partner.resource_type');
    }

    public static function getPluralModelLabel(): string
    {
        return __('partner.resource_types');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name_fr')->label(__('partner.name_fr'))->required()->maxLength(190)
                ->live(onBlur: true)->afterStateUpdated(fn (Forms\Set $set, ?string $state) => $set('slug', Str::slug((string) $state))),
            Forms\Components\TextInput::make('name_en')->label(__('partner.name_en'))->maxLength(190),
            Forms\Components\TextInput::make('slug')->label('Identifiant')->required()->maxLength(60)->alphaDash(),
            Forms\Components\Select::make('kind')->label(__('partner.kind'))->options([
                'room' => __('partner.kind_room'), 'therapist' => __('partner.kind_therapist'), 'equipment' => __('partner.kind_equipment'),
            ])->default('room')->required(),
            Forms\Components\Radio::make('allocation_mode')->label(__('partner.allocation_mode'))->options([
                'pool' => __('partner.mode_pool'), 'unit' => __('partner.mode_unit'),
            ])->descriptions([
                'pool' => __('partner.mode_pool_help'), 'unit' => __('partner.mode_unit_help'),
            ])->default('unit')->required()->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('name_fr')->label(__('partner.resource_type'))->weight('bold'),
                Tables\Columns\TextColumn::make('kind')->label(__('partner.kind'))->formatStateUsing(fn ($state) => __("partner.kind_$state"))->badge()->color('gray'),
                Tables\Columns\TextColumn::make('allocation_mode')->label(__('partner.allocation_mode'))->formatStateUsing(fn ($state) => __("partner.mode_$state")),
                Tables\Columns\TextColumn::make('resources_count')->counts('resources')->label(__('partner.resources')),
                Tables\Columns\TextColumn::make('capacity')->label(__('partner.capacity'))
                    ->getStateUsing(fn (ResourceType $t) => $t->resources()->where('status', 'active')->sum('capacity')),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->emptyStateHeading(__('partner.no_resource_types'))
            ->emptyStateDescription(__('partner.no_resource_types_help'));
    }

    public static function getRelations(): array
    {
        return [ResourcesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListResourceTypes::route('/'),
            'create' => Pages\CreateResourceType::route('/create'),
            'edit' => Pages\EditResourceType::route('/{record}/edit'),
        ];
    }
}
