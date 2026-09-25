<?php

namespace App\Domain\Geo;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Géocodage d'adresse via Nominatim (OpenStreetMap), mis en cache ; retourne null si rien n'est trouvé. */
class Geocoder
{
    /** @return array{lat: float, lng: float, label: string}|null */
    public function search(string $address, ?string $city = null, string $country = 'ma'): ?array
    {
        $query = trim(implode(', ', array_filter([$address, $city])));
        if ($query === '') {
            return null;
        }

        return Cache::remember('geocode:'.md5($country.'|'.$query), now()->addDays(30), function () use ($query, $country) {
            $response = Http::withHeaders(['User-Agent' => config('app.name').' ('.config('app.url').')'])
                ->timeout(8)
                ->get(config('hl.geocoder_url', 'https://nominatim.openstreetmap.org/search'), [
                    'q' => $query,
                    'format' => 'jsonv2',
                    'limit' => 1,
                    'countrycodes' => $country,
                    'accept-language' => app()->getLocale(),
                ]);

            $hit = $response->successful() ? ($response->json()[0] ?? null) : null;
            if (! $hit || ! isset($hit['lat'], $hit['lon'])) {
                return null;
            }

            return ['lat' => round((float) $hit['lat'], 7), 'lng' => round((float) $hit['lon'], 7), 'label' => (string) ($hit['display_name'] ?? $query)];
        });
    }
}
