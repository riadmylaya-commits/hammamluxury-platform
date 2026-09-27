<?php

namespace App\Filament\Partner\Resources;

use App\Filament\Partner\Resources\BlockResource\Pages;
use App\Models\Block;
use App\Models\Resource as SpaResource;
use App\Models\ResourceType;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** Fermetures et indisponibilités : tout l'établissement, un type de ressource ou une ressource précise. */
class BlockResource extends Resource
{
    protected static ?string $model = Block::class;

    protected static ?string $navigationIcon = 'heroicon-o-no-symbol';

    protected static ?int $navigationSort = 40;

    public static function getModelLabel(): string
    {
        return __('partner.block');
    }

    public static function getPluralModelLabel(): string
    {
        return __('partner.blocks');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Radio::make('scope')->label(__('partner.block_scope'))->options([
                'spa' => __('partner.scope_spa'), 'type' => __('partner.scope_type'), 'resource' => __('partner.scope_resource'),
            ])->default('spa')->live()->required()->columnSpanFull(),
            Forms\Components\Select::make('resource_type_id')->label(__('partner.resource_type'))
                ->options(fn () => ResourceType::where('spa_id', Filament::getTenant()->id)->pluck('name_fr', 'id'))
                ->visible(fn (Forms\Get $get) => $get('scope') === 'type')->required(fn (Forms\Get $get) => $get('scope') === 'type'),
            Forms\Components\Select::make('resource_id')->label(__('partner.resource_name'))
                ->options(fn () => SpaResource::where('spa_id', Filament::getTenant()->id)->pluck('name', 'id'))
                ->visible(fn (Forms\Get $get) => $get('scope') === 'resource')->required(fn (Forms\Get $get) => $get('scope') === 'resource'),
            Forms\Components\DateTimePicker::make('start_at')->label(__('partner.block_start'))->seconds(false)->required()->native(false),
            Forms\Components\DateTimePicker::make('end_at')->label(__('partner.block_end'))->seconds(false)->required()->native(false)->after('start_at'),
            Forms\Components\Select::make('kind')->label(__('partner.block_kind'))->options(
                collect(Block::KINDS)->mapWithKeys(fn ($k) => [$k => __("partner.kind_block_$k")])->all()
            )->default('closed')->required(),
            Forms\Components\TextInput::make('note')->label(__('partner.note'))->maxLength(255),
            Forms\Components\Hidden::make('created_by')->default(fn () => Filament::auth()->id()),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('start_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('start_at')->label(__('partner.block_start'))->dateTime('d/m/Y H:i'),
                Tables\Columns\TextColumn::make('end_at')->label(__('partner.block_end'))->dateTime('d/m/Y H:i'),
                Tables\Columns\TextColumn::make('scope')->label(__('partner.block_scope'))->formatStateUsing(fn (Block $b) => match ($b->scope) {
                    'type' => $b->resourceType?->name_fr, 'resource' => $b->resource?->name, default => __('partner.scope_spa'),
                })->badge()->color(fn (Block $b) => $b->scope === 'spa' ? 'danger' : 'warning'),
                Tables\Columns\TextColumn::make('kind')->label(__('partner.block_kind'))->formatStateUsing(fn ($state) => __("partner.kind_block_$state")),
                Tables\Columns\TextColumn::make('note')->label(__('partner.note'))->limit(40),
            ])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()])
            ->emptyStateHeading(__('partner.no_blocks'))
            ->emptyStateDescription(__('partner.no_blocks_help'));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBlocks::route('/'),
            'create' => Pages\CreateBlock::route('/create'),
            'edit' => Pages\EditBlock::route('/{record}/edit'),
        ];
    }
}
