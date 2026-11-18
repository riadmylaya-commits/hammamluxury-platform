<?php

namespace App\Filament\Partner\Pages;

use App\Domain\Support\SupportService;
use App\Models\Spa;
use App\Models\SupportRequest;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\RateLimiter;

/** Assistance écrite du partenaire : formulaire, suivi de ses demandes (établissement courant) et questions fréquentes. */
class Assistance extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-lifebuoy';

    protected static ?int $navigationSort = 90;

    protected static string $view = 'filament.partner.pages.assistance';

    public string $subject = '';

    public string $body = '';

    public ?int $selected = null;

    public string $followUp = '';

    public static function getNavigationLabel(): string
    {
        return __('support.nav');
    }

    public function getTitle(): string
    {
        return __('support.title');
    }

    public function getSubheading(): ?string
    {
        return __('support.intro');
    }

    private function spa(): Spa
    {
        /** @var Spa $spa */
        $spa = Filament::getTenant();

        return $spa;
    }

    /** Demandes de l'établissement courant uniquement. @return Collection<int, SupportRequest> */
    public function requests(): Collection
    {
        return SupportRequest::with('messages')->where('source', 'partner')->where('spa_id', $this->spa()->id)->orderByDesc('id')->limit(50)->get();
    }

    public function select(int $id): void
    {
        $this->selected = $this->selected === $id ? null : $id;
        $this->followUp = '';
    }

    public function send(SupportService $support): void
    {
        $this->validate([
            'subject' => 'required|in:'.implode(',', array_keys(__('support.subjects'))),
            'body' => 'required|string|min:20|max:3000',
        ], attributes: ['subject' => __('support.subject'), 'body' => __('support.description')]);

        $user = auth()->user();
        $key = 'support:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('body', __('support.too_many'));

            return;
        }
        RateLimiter::hit($key, 3600);

        $request = $support->open('partner', $user->name, $user->email, __('support.subjects.'.$this->subject), trim($this->body), app()->getLocale(), $this->spa(), $user);
        $this->reset('subject', 'body');
        Notification::make()->title(__('support.sent', ['ref' => $request->reference]))->success()->send();
    }

    public function sendFollowUp(SupportService $support): void
    {
        $this->validate(['followUp' => 'required|string|min:5|max:3000'], attributes: ['followUp' => __('support.follow_up')]);
        $request = SupportRequest::where('source', 'partner')->where('spa_id', $this->spa()->id)->findOrFail($this->selected);
        $support->followUp($request, trim($this->followUp), auth()->user());
        $this->followUp = '';
        Notification::make()->title(__('support.follow_up_sent'))->success()->send();
    }
}
