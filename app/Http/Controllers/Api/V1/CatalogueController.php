<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalogue\CatalogueService;
use App\Http\Controllers\Controller;
use App\Models\Spa;
use App\Models\SpaHour;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogueController extends Controller
{
    public function __construct(private CatalogueService $catalogue) {}

    public function index(Request $request): JsonResponse
    {
        $spas = $this->catalogue->search($request->string('q')->toString() ?: null, $request->string('category')->toString() ?: null)
            ->with('photos')->paginate(min(50, max(1, (int) $request->integer('per_page', 20))));

        return response()->json([
            'data' => $spas->getCollection()->map(fn (Spa $s) => $this->catalogue->spaCard($s))->values(),
            'meta' => ['total' => $spas->total(), 'page' => $spas->currentPage(), 'last_page' => $spas->lastPage()],
        ]);
    }

    public function show(Spa $spa): JsonResponse
    {
        abort_unless($spa->isPublished(), 404);
        $spa->load(['photos', 'hours']);

        return response()->json(['data' => [
            ...$this->catalogue->spaCard($spa),
            'features' => $spa->features ?? [],
            'hours' => $spa->hours->map(fn ($h) => ['weekday' => $h->weekday, 'opens' => SpaHour::toHhmm((int) $h->opens_min), 'closes' => SpaHour::toHhmm((int) $h->closes_min)])->values(),
            'rules' => ['slot_step_minutes' => $spa->slotStep(), 'min_lead_minutes' => $spa->minLead(), 'max_participants' => (int) config('hl.max_participants')],
            'treatments' => $this->catalogue->treatments($spa)->values(),
        ]]);
    }
}
