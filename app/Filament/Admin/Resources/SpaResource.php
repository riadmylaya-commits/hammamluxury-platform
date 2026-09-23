<?php

namespace App\Filament\Admin\Resources;

use App\Domain\Catalogue\PublicationChecklist;
use App\Filament\Admin\Resources\SpaResource\Pages;
use App\Models\Spa;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class SpaResource extends Resource
{
    protected static ?string $model = Spa::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?int $navigationSort = 20;

    public static function getModelLabel(): string
    {
        return __('admin.spa');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.spas');
    }

    public static function getNavigationBadge(): ?string
    {
        $n = Spa::where('status', 'pending')->count();

        return $n ? (string) $n : null;
    }

    public static function statuses(): array
    {
        return collect(Spa::STATUSES)->mapWithKeys(fn ($s) => [$s => __("partner.spa_status_$s")])->all();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('admin.validation'))->schema([
                Forms\Components\Select::make('status')->label(__('partner.status'))->options(self::statuses())->required(),
                Forms\Components\TextInput::make('slug')->label('Slug')->required()->maxLength(120)->unique(ignoreRecord: true),
                Forms\Components\Textarea::make('status_note')->label(__('admin.status_note'))->rows(3)->helperText(__('admin.status_note_help'))->columnSpanFull(),
            ])->columns(2),
            Forms\Components\Section::make(__('admin.checklist'))->schema([
                Forms\Components\Placeholder::make('checks')->label('')->content(fn (Spa $s) => self::checklist($s)),
            ]),
        ]);
    }

    public static function checklist(Spa $spa): HtmlString
    {
        $html = '<ul class="space-y-1">';
        foreach (PublicationChecklist::checks($spa) as $label => $ok) {
            $html .= '<li>'.($ok ? '✅' : '⛔').' '.e($label).'</li>';
        }
        if (! PublicationChecklist::passes($spa)) {
            $html .= '<li class="font-semibold text-danger-600">'.e(__('admin.checklist_blocking')).'</li>';
        }

        return new HtmlString($html.'</ul>');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('partner'))
            ->columns([
                Tables\Columns\ImageColumn::make('cover')->label('')->getStateUsing(fn (Spa $s) => $s->coverPhoto()?->url())->circular(),
                Tables\Columns\TextColumn::make('name')->label(__('admin.spa'))->searchable()->weight('bold')
                    ->description(fn (Spa $s) => $s->area ? "$s->area · $s->city" : $s->city),
                Tables\Columns\TextColumn::make('partner.company_name')->label(__('admin.partner'))->searchable(),
                Tables\Columns\TextColumn::make('treatments_count')->counts('treatments')->label(__('partner.treatments')),
                Tables\Columns\TextColumn::make('bookings_count')->counts('bookings')->label(__('partner.bookings')),
                Tables\Columns\TextColumn::make('rating')->label(__('admin.rating'))->placeholder('—'),
                Tables\Columns\TextColumn::make('status')->label(__('partner.status'))->badge()
                    ->formatStateUsing(fn ($state) => self::statuses()[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'published' => 'success', 'pending' => 'warning', 'suspended' => 'danger', default => 'gray'
                    }),
            ])
            ->filters([Tables\Filters\SelectFilter::make('status')->options(self::statuses())])
            ->actions([
                Tables\Actions\Action::make('publish')->label(__('admin.publish'))->icon('heroicon-o-eye')->color('success')
                    ->visible(fn (Spa $s) => $s->status !== 'published')->requiresConfirmation()
                    ->modalContent(fn (Spa $s) => self::checklist($s))
                    ->modalSubmitAction(fn ($action, Spa $s) => $action->disabled(! PublicationChecklist::passes($s)))
                    ->action(function (Spa $s) {
                        if (! PublicationChecklist::passes($s)) {
                            Notification::make()->title(__('admin.checklist_blocking'))->body(implode(' · ', PublicationChecklist::failures($s)))->danger()->send();

                            return;
                        }
                        $s->refreshPriceFrom();
                        $s->update(['status' => 'published', 'published_at' => $s->published_at ?? now(), 'status_note' => null]);
                        Notification::make()->title(__('admin.published_ok'))->success()->send();
                    }),
                Tables\Actions\Action::make('preview')->label(__('admin.preview'))->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Spa $s) => route('spa.show', ['locale' => 'fr', 'spa' => $s]), shouldOpenInNewTab: true)
                    ->visible(fn (Spa $s) => $s->status === 'published'),
                Tables\Actions\Action::make('manage')->label(__('admin.manage'))->icon('heroicon-o-wrench-screwdriver')
                    ->url(fn (Spa $s) => url("/partenaire/{$s->slug}")),
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSpas::route('/'),
            'edit' => Pages\EditSpa::route('/{record}/edit'),
        ];
    }
}
