<?php

namespace App\Livewire\Site;

use App\Domain\Security\Honeypot;
use App\Domain\Support\SupportService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Formulaire de contact public : e-mail vers hl.contact_email, anti-spam (leurre + délai) et limitation par IP. */
#[Layout('components.layouts.site')]
class Contact extends Component
{
    public string $name = '';

    public string $email = '';

    public string $subject = '';

    public string $message = '';

    public string $hp_website = '';

    public int $hp_started_at = 0;

    public bool $sent = false;

    public function mount(): void
    {
        $this->hp_started_at = now()->timestamp;
    }

    public function send(): void
    {
        $this->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:190',
            'subject' => 'required|string|max:150',
            'message' => 'required|string|min:20|max:3000',
        ]);

        if (Honeypot::isSpam([Honeypot::FIELD => $this->hp_website, Honeypot::STARTED_FIELD => $this->hp_started_at])) {
            $this->sent = true;

            return;
        }

        $key = 'contact:'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('message', __('pages.contact.too_many'));

            return;
        }
        RateLimiter::hit($key, 3600);

        // Demande enregistrée, accusé de réception à l'expéditeur, alerte à l'administration.
        app(SupportService::class)->open('client', trim($this->name), trim($this->email), trim($this->subject), trim($this->message), app()->getLocale());

        $this->reset('name', 'email', 'subject', 'message');
        $this->sent = true;
    }

    public function render(): View
    {
        return view('livewire.site.contact')->title(__('pages.contact.title'));
    }
}
