<?php

namespace App\Livewire\Site;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Pages d'information (CGU, confidentialité, FAQ) : contenu rédigé par locale dans resources/views/pages/{locale}/. */
#[Layout('components.layouts.site')]
class StaticPage extends Component
{
    /** slug de route => vue de contenu */
    public const PAGES = ['cgu' => 'terms', 'confidentialite' => 'privacy', 'faq' => 'faq'];

    public string $page = '';

    public function mount(string $page): void
    {
        abort_unless(isset(self::PAGES[$page]), 404);
        $this->page = $page;
    }

    public function render(): View
    {
        $key = self::PAGES[$this->page];
        $locale = app()->getLocale();
        $content = view()->exists("pages.$locale.$key") ? "pages.$locale.$key" : "pages.fr.$key";

        return view('livewire.site.static-page', ['content' => $content, 'key' => $key])->title(__("pages.$key.title"));
    }
}
