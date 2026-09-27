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
        foreach ($this->candidates($address, $city) as $query) {
            if ($hit = $this->lookup($query, $country)) {
                return $hit;
            }
        }

        return null;
    }

    /**
     * Variantes de requête, de la plus précise à la plus simple : adresse complète, puis sans les
     * segments finaux (pays, ville répétée, quartier), toujours suivie de la ville.
     *
     * @return list<string>
     */
    private function candidates(string $address, ?string $city): array
    {
        $city = trim((string) $city);
        $parts = array_values(array_filter(array_map('trim', preg_split('/\s*,\s*/', $address) ?: [])));
        $countryNames = ['maroc', 'morocco', 'marruecos', 'ma'];
        $parts = array_values(array_filter($parts, fn (string $p) => ! in_array(mb_strtolower($p), $countryNames, true)
            && ($city === '' || mb_strtolower($p) !== mb_strtolower($city))));

        $out = [];
        for ($n = count($parts); $n >= 1; $n--) {
            $q = implode(', ', array_slice($parts, 0, $n));
            $out[] = trim(implode(', ', array_filter([$q, $city])));
        }
        if ($parts === [] && $city !== '') {
            $out[] = $city;
        }

        return array_values(array_unique(array_filter($out)));
    }

    /** @return array{lat: float, lng: float, label: string}|null */
    private function lookup(string $query, string $country): ?array
    {
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
