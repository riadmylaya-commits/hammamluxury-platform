<?php

namespace App\Livewire\Site;

use App\Domain\Catalogue\CatalogueService;
use App\Models\Spa;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.site')]
class SpaShow extends Component
{
    public Spa $spa;

    #[Url]
    public string $date = '';

    /** Prévisualisation privée : fiche non publiée affichée « comme un client » à un administrateur connecté, jamais indexée. */
    public bool $preview = false;

    public function mount(Spa $spa, CatalogueService $catalogue): void
    {
        $public = $catalogue->bookableSpas()->whereKey($spa->id)->exists();
        $this->preview = ! $public && (bool) auth()->user()?->isAdmin();
        abort_unless($public || $this->preview, 404);
        $this->spa = $spa->load(['photos', 'hours', 'categories', 'amenities']);
    }

    public function render(CatalogueService $catalogue): View
    {
        $this->date = CatalogueService::parseDate($this->date)?->format('Y-m-d') ?? '';
        $card = $catalogue->spaCard($this->spa);
        $treatments = $catalogue->treatments($this->spa);
        $reviews = $this->spa->reviews()->published()->with('photos')->latest('published_at')->limit(30)->get();

        return view('livewire.site.spa-show', [
            'preview' => $this->preview, 'card' => $card, 'treatments' => $treatments, 'reviews' => $reviews, 'bookParams' => array_filter(['date' => $this->date]),
            'galleryI18n' => ['of' => __('ui.photo_of'), 'close' => __('ui.close'), 'prev' => __('ui.prev'), 'next' => __('ui.next')]])
            ->title($this->spa->name.' · '.$this->spa->city);
    }
}
