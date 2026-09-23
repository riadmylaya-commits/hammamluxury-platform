<?php

namespace App\Filament\Partner\Pages;

use App\Models\Partner;
use App\Models\User;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Pages\Auth\Register as BaseRegister;
use Illuminate\Database\Eloquent\Model;

/** Inscription partenaire : crée l'utilisateur (rôle partner) et sa fiche société en attente de validation. */
class Register extends BaseRegister
{
    public function form(Form $form): Form
    {
        return $form->schema([
            $this->getNameFormComponent(),
            TextInput::make('company_name')->label(__('partner.company_name'))->required()->maxLength(190),
            TextInput::make('phone')->label(__('partner.phone'))->tel()->required()->maxLength(40),
            $this->getEmailFormComponent(),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
        ]);
    }

    protected function handleRegistration(array $data): Model
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'phone' => $data['phone'],
            'role' => 'partner',
            'locale' => app()->getLocale(),
        ]);

        Partner::create([
            'user_id' => $user->id,
            'company_name' => $data['company_name'],
            'contact_phone' => $data['phone'],
            'status' => 'pending',
        ]);

        return $user;
    }
}
