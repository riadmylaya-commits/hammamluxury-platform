<?php

namespace App\Filament\Partner\Pages;

use App\Domain\Security\Honeypot;
use App\Filament\Forms\Components\PhoneField;
use App\Models\ActivityLog;
use App\Models\Partner;
use App\Models\User;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Pages\Auth\Register as BaseRegister;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** Inscription partenaire : crée l'utilisateur (rôle partner) et sa fiche société en attente de validation. */
class Register extends BaseRegister
{
    public function form(Form $form): Form
    {
        return $form->schema([
            Group::make([
                TextInput::make('first_name')->label(__('partner.first_name'))->required()->maxLength(100)->autofocus(),
                TextInput::make('last_name')->label(__('partner.last_name'))->required()->maxLength(100),
            ])->columns(2),
            TextInput::make('company_name')->label(__('partner.company_name'))->required()->maxLength(190),
            PhoneField::make('phone', __('partner.phone'), required: true),
            Toggle::make('whatsapp_same')->label(__('partner.whatsapp_same'))->default(true)->live(),
            PhoneField::make('whatsapp', __('partner.whatsapp'), required: true)->visible(fn ($get) => ! $get('whatsapp_same')),
            $this->getEmailFormComponent(),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
            ...Honeypot::fields(),
        ]);
    }

    protected function handleRegistration(array $data): Model
    {
        if (Honeypot::isSpam($data)) {
            ActivityLog::record('registration.spam_blocked', null, ['email' => $data['email'] ?? null], 'site');
            throw ValidationException::withMessages(['data.email' => __('partner.spam_blocked')]);
        }

        $whatsapp = ($data['whatsapp_same'] ?? true) ? $data['phone'] : ($data['whatsapp'] ?? null);

        $user = User::create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'phone' => $data['phone'],
            'whatsapp' => $whatsapp,
            'role' => 'partner',
            'locale' => app()->getLocale(),
        ]);

        $partner = Partner::create([
            'user_id' => $user->id,
            'company_name' => $data['company_name'],
            'contact_phone' => $data['phone'],
            'status' => 'pending',
        ]);

        ActivityLog::record('partner.registered', $partner, ['email' => $user->email], 'site');

        return $user;
    }
}
