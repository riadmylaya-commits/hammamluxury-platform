<?php

namespace App\Filament\Shared\Pages;

use App\Domain\Security\TwoFactor;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\SimplePage;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;

/** Saisie du code TOTP ou d'un code de secours après le mot de passe. */
class TwoFactorChallenge extends SimplePage implements HasForms
{
    use InteractsWithForms;
    use WithRateLimiting;

    protected static string $view = 'filament.pages.two-factor-challenge';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(TwoFactor $twoFactor): void
    {
        $user = auth()->user();
        if (! $user->hasTwoFactor()) {
            $this->redirect(filament()->getUrl());

            return;
        }
        if ($twoFactor->hasPassed($user)) {
            $this->redirectIntended(filament()->getUrl());

            return;
        }
        $this->form->fill();
    }

    public function getTitle(): string|Htmlable
    {
        return __('security.challenge_title');
    }

    public function getHeading(): string|Htmlable
    {
        return __('security.challenge_title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('security.challenge_help');
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('code')->label(__('security.code'))->required()->autofocus()->autocomplete('one-time-code')->maxLength(12),
        ])->statePath('data');
    }

    public function verify(TwoFactor $twoFactor): void
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $e) {
            throw ValidationException::withMessages(['data.code' => __('security.throttled', ['seconds' => $e->secondsUntilAvailable])]);
        }

        $code = (string) ($this->form->getState()['code'] ?? '');

        if (! $twoFactor->challenge(auth()->user(), $code)) {
            throw ValidationException::withMessages(['data.code' => __('security.invalid_code')]);
        }

        $this->redirectIntended(filament()->getUrl());
    }

    public function logout(): void
    {
        filament()->auth()->logout();
        session()->invalidate();
        session()->regenerateToken();
        $this->redirect(filament()->getLoginUrl());
    }
}
