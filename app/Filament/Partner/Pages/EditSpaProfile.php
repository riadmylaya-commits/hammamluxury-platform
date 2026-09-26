<?php

namespace App\Filament\Partner\Pages;

use App\Filament\Partner\Forms\SpaForm;
use App\Models\Spa;
use Filament\Actions\Action;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Tenancy\EditTenantProfile;

/** Fiche établissement : identité, adresse, photos, horaires, règles de réservation. */
class EditSpaProfile extends EditTenantProfile
{
    public static function getLabel(): string
    {
        return __('partner.spa_profile');
    }

    public function form(Form $form): Form
    {
        return $form->schema(SpaForm::full());
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Spa $spa */
        $spa = $this->tenant;

        return $data + ['location' => $spa->location];
    }

    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction(),
            Action::make('submit')
                ->label(__('partner.submit_for_review'))
                ->color('warning')
                ->visible(fn () => in_array($this->tenant->status, ['draft', 'suspended'], true))
                ->requiresConfirmation()
                ->modalDescription(__('partner.submit_for_review_help'))
                ->action(function () {
                    $this->save();
                    /** @var Spa $spa */
                    $spa = $this->tenant;
                    $spa->update(['status' => 'pending']);
                    Notification::make()->title(__('partner.submitted'))->success()->send();
                }),
        ];
    }
}
