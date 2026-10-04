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

    public function mount(Spa $spa, CatalogueService $catalogue): void
    {
        abort_unless($catalogue->bookableSpas()->whereKey($spa->id)->exists(), 404);
        $this->spa = $spa->load(['photos', 'hours', 'categories', 'amenities']);
    }

    public function render(CatalogueService $catalogue): View
    {
        $this->date = CatalogueService::parseDate($this->date)?->format('Y-m-d') ?? '';
        $card = $catalogue->spaCard($this->spa);
        $treatments = $catalogue->treatments($this->spa);
        $reviews = $this->spa->reviews()->published()->with('photos')->latest('published_at')->limit(30)->get();
        $criteria = [];
        foreach ($reviews->pluck('criteria')->filter() as $set) {
            foreach ($set as $k => $v) {
                $criteria[$k][] = $v;
            }
        }
        $criteriaAvg = array_map(fn ($vals) => round(array_sum($vals) / count($vals), 1), $criteria);

        return view('livewire.site.spa-show', ['card' => $card, 'treatments' => $treatments, 'reviews' => $reviews, 'criteriaAvg' => $criteriaAvg, 'bookParams' => array_filter(['date' => $this->date]),
            'galleryI18n' => ['of' => __('ui.photo_of'), 'close' => __('ui.close'), 'prev' => __('ui.prev'), 'next' => __('ui.next')]])
            ->title($this->spa->name.' · '.$this->spa->city);
    }
}
