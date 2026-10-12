<?php

namespace App\Filament\Partner\Resources\ResourceTypeResource\RelationManagers;

use App\Models\Resource;
use App\Models\ResourceType;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class ResourcesRelationManager extends RelationManager
{
    protected static string $relationship = 'resources';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('partner.resources');
    }

    protected static function getModelLabel(): ?string
    {
        return __('partner.unit');
    }

    protected static function getPluralModelLabel(): ?string
    {
        return __('partner.resources');
    }

    public function form(Form $form): Form
    {
        /** @var ResourceType $type */
        $type = $this->getOwnerRecord();
        $isPool = $type->allocation_mode === 'pool';
        $family = $type->family();
        $mode = $isPool ? 'pool' : 'unit';

        return $form->schema([
            Forms\Components\TextInput::make('name')->label(__('partner.resource_name'))->required()->maxLength(190)
                ->placeholder(__("partner.unit_name_ex.$family"))->helperText(__("partner.unit_name_help.$mode")),
            Forms\Components\TextInput::make('capacity')->label(__("partner.unit_capacity.$mode"))->numeric()->minValue(1)->maxValue(200)
                ->default($isPool ? ($family === 'hammam' ? 6 : 4) : 1)->required()
                ->helperText(__("partner.unit_capacity_help.$family.$mode")),
            Forms\Components\Hidden::make('min_party')->default(1),
            Forms\Components\Hidden::make('max_party')->default(1),
            Forms\Components\Select::make('status')->label(__('partner.status'))->options(['active' => __('partner.active'), 'inactive' => __('partner.inactive')])->default('active')->required()
                ->helperText(__('partner.unit_status_help')),
        ])->columns(2);
    }

    /** Le partenaire ne saisit qu'un nombre de personnes : capacité = max. personnes, min. = 1. */
    public static function normalize(array $data): array
    {
        $data['capacity'] = max(1, (int) ($data['capacity'] ?? 1));
        $data['max_party'] = $data['capacity'];
        $data['min_party'] = 1;

        return $data;
    }

    public function table(Table $table): Table
    {
        /** @var ResourceType $type */
        $type = $this->getOwnerRecord();
        $family = $type->family();

        return $table
            ->reorderable('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label(__('partner.resource_name')),
                Tables\Columns\TextColumn::make('capacity')->label(__('partner.unit_capacity.'.$type->allocation_mode))
                    ->formatStateUsing(fn ($state) => trans_choice('partner.people_count', (int) $state, ['count' => $state])),
                Tables\Columns\IconColumn::make('status')->label(__('partner.status'))->getStateUsing(fn ($record) => $record->status === 'active')->boolean(),
            ])
            ->headerActions([Tables\Actions\CreateAction::make()->label(__('partner.add_resource'))
                ->using(function (array $data, Tables\Actions\CreateAction $action) use ($type): Resource {
                    abort_unless((int) $type->spa_id === (int) Filament::getTenant()->id, 403);

                    return $this->protectedMutation($action, fn () => $type->resources()->create(
                        array_replace(self::normalize($data), ['spa_id' => $type->spa_id, 'resource_type_id' => $type->id])
                    ));
                })])
            ->actions([
                Tables\Actions\EditAction::make()->using(function (Resource $record, array $data, Tables\Actions\EditAction $action) use ($type): Resource {
                    abort_unless((int) $type->spa_id === (int) Filament::getTenant()->id
                        && (int) $record->spa_id === (int) $type->spa_id && (int) $record->resource_type_id === (int) $type->id, 403);

                    return $this->protectedMutation($action, function () use ($record, $data, $type): Resource {
                        $record->update(array_replace(self::normalize($data), ['spa_id' => $type->spa_id, 'resource_type_id' => $type->id]));

                        return $record;
                    });
                })->visible(fn (Resource $record) => ! $record->archived_at),
                Tables\Actions\Action::make('archive')->label(__('booking.resource_archive'))->icon('heroicon-o-archive-box')
                    ->color('warning')->requiresConfirmation()->modalDescription(__('booking.resource_archive_help'))
                    ->visible(fn (Resource $record) => ! $record->archived_at)
                    ->action(function (Resource $record, Tables\Actions\Action $action) use ($type): void {
                        abort_unless((int) $type->spa_id === (int) Filament::getTenant()->id
                            && (int) $record->spa_id === (int) $type->spa_id && (int) $record->resource_type_id === (int) $type->id, 403);
                        try {
                            $record->delete();
                        } catch (ValidationException $e) {
                            Notification::make()->danger()->title(collect($e->errors())->flatten()->first())->send();
                            $action->halt();
                        }
                    }),
            ])
            ->emptyStateHeading(__('partner.no_resources'))
            ->emptyStateDescription(__('partner.no_resources_help.'.$family));
    }

    private function protectedMutation(Tables\Actions\Action $action, callable $operation): mixed
    {
        try {
            return $operation();
        } catch (ValidationException $e) {
            $path = $this->getMountedTableActionForm($action)?->getStatePath() ?? 'mountedTableActionsData.0';
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($messages, $field) => [$path.'.'.$field => $messages])->all());
        }
    }
}
