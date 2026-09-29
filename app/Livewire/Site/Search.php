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

    #[Url]
    public string $sort = 'recommended';

    public function render(CatalogueService $catalogue): View
    {
        $date = CatalogueService::parseDate($this->date);
        if (! $date) {
            $this->date = '';
        }
        if (! in_array($this->sort, CatalogueService::SORTS, true)) {
            $this->sort = 'recommended';
        }

        $spas = $catalogue->search($this->q, $this->category ?: null, $date, $this->sort)->limit(50)->get()
            ->map(fn (Spa $s) => $catalogue->spaCard($s) + ['treatments_count' => $s->treatments_count]);

        return view('livewire.site.search', ['spas' => $spas, 'searchDate' => $date?->format('Y-m-d')])
            ->title($this->q ? __('ui.results_for', ['q' => $this->q]) : __('ui.all_results'));
    }
}
