<?php

namespace App\Livewire\Site;

use App\Domain\Catalogue\CatalogueService;
use App\Models\Spa;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.site')]
class Home extends Component
{
    public string $q = '';

    public string $category = '';

    public string $date = '';

    public function render(CatalogueService $catalogue): View
    {
        $cities = Spa::query()->fromSub($catalogue->bookableSpas()->select('city'), 'b')
            ->selectRaw('city, COUNT(*) AS n')->groupBy('city')->orderByDesc('n')->limit(4)->get();

        // Photo de la destination : couverture du premier établissement publié de la ville qui en a une.
        $cities->each(fn ($c) => $c->photo = $catalogue->bookableSpas()->where('city', $c->city)->with('photos')->orderBy('id')->get()
            ->map(fn (Spa $s) => $s->coverPhoto()?->url())->filter()->first());

        return view('livewire.site.home', [
            'cities' => $cities,
            'featured' => $catalogue->search(null)->limit(4)->get()->map(fn (Spa $s) => $catalogue->spaCard($s) + ['treatments_count' => $s->treatments_count]),
        ])->title(__('ui.hero_title'));
    }
}
