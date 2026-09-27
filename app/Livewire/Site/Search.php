<?php

namespace App\Livewire\Site;

use App\Domain\Catalogue\CatalogueService;
use App\Models\Spa;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.site')]
class Search extends Component
{
    #[Url]
    public string $q = '';

    #[Url]
    public string $category = '';

    #[Url]
    public string $date = '';

    public function render(CatalogueService $catalogue): View
    {
        $spas = $catalogue->search($this->q, $this->category ?: null)->limit(50)->get()
            ->map(fn (Spa $s) => $catalogue->spaCard($s) + ['treatments_count' => $s->treatments_count]);

        return view('livewire.site.search', ['spas' => $spas])
            ->title($this->q ? __('ui.results_for', ['q' => $this->q]) : __('ui.all_results'));
    }
}
