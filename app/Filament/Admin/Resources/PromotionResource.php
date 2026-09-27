<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\PromotionResource\Pages;
use App\Models\Promotion;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** Promotions (codes et remises) : structure prête, application au devis prévue dans une étape ultérieure. */
class PromotionResource extends Resource
{
    protected static ?string $model = Promotion::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?int $navigationSort = 40;

    public static function getModelLabel(): string
    {
        return __('admin.promotion');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.promotions');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('label_fr')->label(__('partner.name_fr'))->required()->maxLength(190),
            Forms\Components\TextInput::make('label_en')->label(__('partner.name_en'))->maxLength(190),
            Forms\Components\TextInput::make('code')->label(__('admin.promo_code'))->maxLength(40)->unique(ignoreRecord: true)->helperText(__('admin.promo_code_help')),
            Forms\Components\Select::make('spa_id')->label(__('admin.spa'))->relationship('spa', 'name')->searchable()->preload()->placeholder(__('admin.all_spas')),
            Forms\Components\Select::make('type')->label(__('admin.promo_type'))->options(['percent' => '%', 'amount' => config('hl.currency')])->default('percent')->required(),
            Forms\Components\TextInput::make('value')->label(__('admin.promo_value'))->numeric()->minValue(0)->required(),
            Forms\Components\DatePicker::make('starts_on')->label(__('admin.starts_on'))->native(false),
            Forms\Components\DatePicker::make('ends_on')->label(__('admin.ends_on'))->native(false)->afterOrEqual('starts_on'),
            Forms\Components\Select::make('status')->label(__('partner.status'))->options(['active' => __('partner.active'), 'inactive' => __('partner.inactive')])->default('active')->required(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('label_fr')->label(__('admin.promotion'))->searchable(),
            Tables\Columns\TextColumn::make('code')->label(__('admin.promo_code'))->badge()->color('gray')->placeholder('—'),
            Tables\Columns\TextColumn::make('spa.name')->label(__('admin.spa'))->placeholder(__('admin.all_spas')),
            Tables\Columns\TextColumn::make('value')->label(__('admin.promo_value'))->formatStateUsing(fn (Promotion $p) => $p->type === 'percent' ? "-$p->value %" : '-'.number_format((float) $p->value, 0, ',', ' ').' '.config('hl.currency')),
            Tables\Columns\TextColumn::make('starts_on')->label(__('admin.starts_on'))->date('d/m/Y')->placeholder('—'),
            Tables\Columns\TextColumn::make('ends_on')->label(__('admin.ends_on'))->date('d/m/Y')->placeholder('—'),
            Tables\Columns\IconColumn::make('status')->label(__('partner.status'))->getStateUsing(fn ($record) => $record->status === 'active')->boolean(),
        ])->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPromotions::route('/'),
            'create' => Pages\CreatePromotion::route('/create'),
            'edit' => Pages\EditPromotion::route('/{record}/edit'),
        ];
    }
}
