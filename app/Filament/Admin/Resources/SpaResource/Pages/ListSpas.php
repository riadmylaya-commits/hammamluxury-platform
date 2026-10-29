<?php

namespace App\Filament\Admin\Resources\SpaResource\Pages;

use App\Domain\Partner\OwnerAssignmentService;
use App\Filament\Admin\Resources\SpaResource;
use App\Filament\Forms\Components\PhoneField;
use App\Filament\Partner\Forms\SpaForm;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListSpas extends ListRecords
{
    protected static string $resource = SpaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('create_spa')
                ->label(__('admin.create_spa'))
                ->icon('heroicon-o-plus')
                ->modalDescription(__('admin.create_spa_help'))
                ->form([
                    Forms\Components\Grid::make(2)->schema([
                        ...SpaForm::identity(),
                        Forms\Components\TextInput::make('address')->label(__('partner.address'))->maxLength(255)->columnSpanFull(),
                        PhoneField::make('phone', __('partner.spa_phone'))->columnSpanFull(),
                        Forms\Components\TextInput::make('email')->label(__('partner.spa_email'))->email()->maxLength(190)->columnSpanFull(),
                    ]),
                ])
                ->action(function (array $data) {
                    $spa = app(OwnerAssignmentService::class)->createSpa(auth()->user(), $data);
                    Notification::make()->title(__('admin.create_spa_ok'))->success()->send();

                    return redirect()->to(url("/partenaire/{$spa->slug}"));
                }),
        ];
    }
}
