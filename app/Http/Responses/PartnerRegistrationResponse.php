<?php

namespace App\Http\Responses;

use Filament\Facades\Filament;
use Filament\Http\Responses\Auth\Contracts\RegistrationResponse;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

/** Après inscription : page « confirmez votre e-mail » (jamais l'URL mémorisée d'une visite précédente). */
class PartnerRegistrationResponse implements RegistrationResponse
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        session()->forget('url.intended');
        $user = Filament::auth()->user();
        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail() && Filament::hasEmailVerification()) {
            return redirect()->to(Filament::getEmailVerificationPromptUrl());
        }

        return redirect()->to(Filament::getUrl());
    }
}
