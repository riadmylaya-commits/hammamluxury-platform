<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Booking\BookingException;
use App\Domain\Booking\BookingService;
use App\Domain\Booking\CapacityEngine;
use App\Domain\Booking\QuoteBuilder;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Spa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mêmes services métier que le front Livewire : aucune logique de prix/disponibilité ici.
 * Format de sélection : {treatment, party, extras:{id:qty}} ou {participants:[{treatment, extras}]}.
 */
class BookingController extends Controller
{
    public function __construct(
        private BookingService $bookings,
        private QuoteBuilder $quotes,
        private CapacityEngine $engine,
    ) {}

    public function quote(Request $request, Spa $spa): JsonResponse
    {
        abort_unless($spa->isPublished(), 404);

        return $this->guard(function () use ($spa, $request) {
            $quote = $this->quotes->fromRequest($spa, $request->all());

            return response()->json(['data' => $quote], $quote['ok'] ? 200 : 422);
        });
    }

    public function availability(Request $request, Spa $spa): JsonResponse
    {
        abort_unless($spa->isPublished(), 404);
        $data = $request->validate(['date' => 'required|date_format:Y-m-d']);

        return $this->guard(function () use ($spa, $request, $data) {
            $quote = $this->quotes->fromRequest($spa, $request->all());
            if (! $quote['ok']) {
                return response()->json(['message' => implode(' ', $quote['errors']), 'errors' => $quote['errors']], 422);
            }
            $av = $this->engine->availability($spa, $data['date'], $quote['items']);

            return response()->json(['data' => ['date' => $data['date'], 'duration_min' => $quote['duration_min'], 'total' => $quote['total'], ...$av]]);
        });
    }

    public function intent(Request $request, Spa $spa): JsonResponse
    {
        $request->validate(['date' => 'required|date_format:Y-m-d', 'time' => 'required|date_format:H:i']);

        return $this->guard(fn () => response()->json(['data' => $this->bookings->prepare($spa, $request->all())], 201));
    }

    public function store(Request $request, Spa $spa): JsonResponse
    {
        $data = $request->validate([
            'intent' => 'required|string',
            'first_name' => 'required|string|max:80',
            'last_name' => 'required|string|max:80',
            'email' => 'required|email|max:190',
            'phone' => 'required|string|max:40',
            'hotel' => 'nullable|string|max:190',
            'note' => 'nullable|string|max:1000',
        ]);

        return $this->guard(function () use ($spa, $data) {
            $booking = $this->bookings->confirmIntent($spa, $data['intent'], collect($data)->except('intent')->all());

            return response()->json(['data' => $this->serialize($booking)], 201);
        });
    }

    public function show(string $token): JsonResponse
    {
        $booking = Booking::where('manage_token', $token)->with(['spa', 'participants'])->firstOrFail();

        return response()->json(['data' => $this->serialize($booking)]);
    }

    public function cancel(string $token): JsonResponse
    {
        $booking = Booking::where('manage_token', $token)->firstOrFail();

        return $this->guard(fn () => response()->json(['data' => $this->serialize($this->bookings->cancel($booking, 'client')->fresh(['spa', 'participants']))]));
    }

    /** Représentation client : les coordonnées directes du spa n'apparaissent qu'une fois confirmé. */
    private function serialize(Booking $b): array
    {
        $spa = ['name' => $b->spa->name, 'city' => $b->spa->city, 'area' => $b->spa->area, 'slug' => $b->spa->slug];
        if ($b->isConfirmed()) {
            $spa += ['address' => $b->spa->address, 'phone' => $b->spa->phone, 'lat' => $b->spa->lat, 'lng' => $b->spa->lng];
        }

        return [
            'reference' => $b->reference,
            'status' => $b->status,
            'start_at' => $b->start_at?->toIso8601String(),
            'end_at' => $b->end_at?->toIso8601String(),
            'party' => $b->party,
            'duration_min' => $b->duration_min,
            'total' => (float) $b->total,
            'currency' => config('hl.currency'),
            'expires_at' => $b->isWaiting() ? $b->expires_at?->toIso8601String() : null,
            'manage_token' => $b->manage_token,
            'spa' => $spa,
            'participants' => $b->participants->map(fn ($p) => [
                'participant_no' => $p->participant_no, 'treatment' => $p->treatment_name, 'party' => $p->party,
                'extras' => $p->extras ?? [], 'duration_min' => $p->duration_min, 'price' => (float) $p->price,
            ])->values(),
        ];
    }

    private function guard(callable $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (BookingException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], $e->status);
        }
    }
}
