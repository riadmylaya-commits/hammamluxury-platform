<?php

namespace App\Filament\Shared\Pages;

use App\Domain\Security\TwoFactor;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\SimplePage;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** Activation / gestion de la double authentification (QR code, confirmation, codes de secours, désactivation). */
class TwoFactorSetup extends SimplePage implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'filament.pages.two-factor-setup';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public ?string $secret = null;

    /** @var list<string> */
    public array $recoveryCodes = [];

    public function mount(TwoFactor $twoFactor): void
    {
        if (! auth()->user()->hasTwoFactor()) {
            $this->secret = $twoFactor->generateSecret();
        }
        $this->form->fill();
    }

    public function getTitle(): string|Htmlable
    {
        return __('security.title');
    }

    public function getHeading(): string|Htmlable
    {
        return __('security.title');
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('code')->label(__('security.code'))->required()->autocomplete('one-time-code')->maxLength(6),
        ])->statePath('data');
    }

    public function qrSvg(TwoFactor $twoFactor): string
    {
        return $this->secret ? $twoFactor->qrSvg(auth()->user(), $this->secret) : '';
    }

    public function enable(TwoFactor $twoFactor): void
    {
        $code = (string) ($this->form->getState()['code'] ?? '');
        $codes = $this->secret ? $twoFactor->enable(auth()->user(), $this->secret, $code) : null;

        if ($codes === null) {
            throw ValidationException::withMessages(['data.code' => __('security.invalid_code')]);
        }

        $this->recoveryCodes = $codes;
        $this->secret = null;
        $this->form->fill();
        Notification::make()->title(__('security.enabled'))->success()->send();
    }

    public function regenerateAction(): Action
    {
        return Action::make('regenerate')->label(__('security.regenerate'))->color('gray')->icon('heroicon-o-arrow-path')
            ->requiresConfirmation()
            ->form([TextInput::make('password')->label(__('security.current_password'))->password()->required()->currentPassword()])
            ->action(function (TwoFactor $twoFactor) {
                $this->recoveryCodes = $twoFactor->regenerateRecoveryCodes(auth()->user());
                Notification::make()->title(__('security.regenerated'))->success()->send();
            });
    }

    public function disableAction(): Action
    {
        return Action::make('disable')->label(__('security.disable'))->color('danger')->icon('heroicon-o-shield-exclamation')
            ->visible(fn () => auth()->user()->hasTwoFactor() && ! auth()->user()->mustEnableTwoFactor())
            ->requiresConfirmation()->modalDescription(__('security.disable_confirm'))
            ->form([
                TextInput::make('password')->label(__('security.current_password'))->password()->required()->currentPassword(),
                TextInput::make('code')->label(__('security.code'))->required()->maxLength(12),
            ])
            ->action(function (array $data, TwoFactor $twoFactor) {
                $user = auth()->user();
                if (! Hash::check($data['password'], $user->password) || ! $twoFactor->challenge($user, $data['code'])) {
                    Notification::make()->title(__('security.invalid_code'))->danger()->send();

                    return;
                }
                $twoFactor->disable($user);
                $this->recoveryCodes = [];
                $this->secret = $twoFactor->generateSecret();
                Notification::make()->title(__('security.disabled'))->success()->send();
            });
    }

    public function continueUrl(): string
    {
        return filament()->getUrl();
    }
}
