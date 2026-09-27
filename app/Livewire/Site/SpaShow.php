<?php

namespace App\Livewire\Site;

use App\Domain\Catalogue\CatalogueService;
use App\Models\Spa;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.site')]
class SpaShow extends Component
{
    public Spa $spa;

    public function mount(Spa $spa, CatalogueService $catalogue): void
    {
        abort_unless($catalogue->bookableSpas()->whereKey($spa->id)->exists(), 404);
        $this->spa = $spa->load(['photos', 'hours', 'categories', 'amenities']);
    }

    public function render(CatalogueService $catalogue): View
    {
        $card = $catalogue->spaCard($this->spa);
        $treatments = $catalogue->treatments($this->spa);

        return view('livewire.site.spa-show', ['card' => $card, 'treatments' => $treatments])
            ->title($this->spa->name.' · '.$this->spa->city);
    }
}
