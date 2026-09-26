<?php

namespace App\Filament\Admin\Resources\SpaResource\RelationManagers;

use App\Models\ActivityLog;
use App\Models\Extra;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Extras d'un établissement : seule l'administration décide s'ils sont soumis à commission. */
class ExtrasRelationManager extends RelationManager
{
    protected static string $relationship = 'extras';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('partner.extras');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($q) => $q->with('treatment'))
            ->columns([
                Tables\Columns\TextColumn::make('name_fr')->label(__('partner.extra'))->description(fn (Extra $e) => $e->treatment?->tr('name')),
                Tables\Columns\TextColumn::make('price')->label(__('partner.extra_price'))->money(config('hl.currency'), locale: 'fr'),
                Tables\Columns\TextColumn::make('extra_min')->label(__('partner.extra_min'))->suffix(' min'),
                Tables\Columns\ToggleColumn::make('commissionable')->label(__('partner.commissionable_extra'))
                    ->afterStateUpdated(fn (Extra $e, bool $state) => ActivityLog::record($state ? 'extra.commissionable' : 'extra.non_commissionable', $e, ['name' => $e->name_fr])),
            ])
            ->description(__('partner.commissionable_extra_help'))
            ->emptyStateHeading(__('partner.no_extras'));
    }
}
