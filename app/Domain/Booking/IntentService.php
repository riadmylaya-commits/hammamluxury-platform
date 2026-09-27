<?php

namespace App\Domain\Booking;

use App\Models\Spa;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Intention de réservation signée : le devis, la date et l'heure validés côté serveur sont
 * stockés en cache et référencés par un jeton HMAC. Le client ne peut ni modifier le prix
 * ni rejouer un jeton déjà consommé. Aucune capacité n'est bloquée par une intention.
 */
class IntentService
{
    public function ttlSeconds(): int
    {
        return max(60, 60 * (int) config('hl.intent_ttl_minutes'));
    }

    /** @return array{token:string, id:string, expires_at:int} */
    public function create(array $payload): array
    {
        $id = Str::random(24);
        $expires = time() + $this->ttlSeconds();
        $payload += ['id' => $id, 'created_at' => time(), 'expires_at' => $expires];
        Cache::put($this->key($id), $payload, $this->ttlSeconds());

        return ['token' => $id.'.'.$this->sign($id, $payload), 'id' => $id, 'expires_at' => $expires];
    }

    /** Résout un jeton ; lève une BookingException si invalide, altéré, expiré ou déjà consommé. */
    public function resolve(string $token, ?Spa $spa = null): array
    {
        [$id, $sig] = array_pad(explode('.', $token, 2), 2, '');
        $payload = $id !== '' ? Cache::get($this->key($id)) : null;
        if (! is_array($payload)) {
            throw BookingException::make('intent_expired', 'intent_expired', [], 410);
        }
        if (! hash_equals($this->sign($id, $payload), $sig)) {
            throw BookingException::make('intent_tampered', 'intent_invalid', [], 403);
        }
        if ((int) $payload['expires_at'] < time()) {
            throw BookingException::make('intent_expired', 'intent_expired', [], 410);
        }
        if (! empty($payload['consumed_booking_id'])) {
            throw BookingException::make('intent_replayed', 'intent_replayed', [], 409);
        }
        if ($spa && (int) $payload['spa_id'] !== $spa->id) {
            throw BookingException::make('intent_spa', 'intent_invalid', [], 403);
        }

        return $payload;
    }

    /** Marque un jeton comme consommé (anti-rejeu) en conservant la trace jusqu'à expiration. */
    public function consume(string $id, int $bookingId): void
    {
        $payload = Cache::get($this->key($id));
        if (is_array($payload)) {
            $payload['consumed_booking_id'] = $bookingId;
            Cache::put($this->key($id), $payload, $this->ttlSeconds());
        }
    }

    private function key(string $id): string
    {
        return 'hl:intent:'.$id;
    }

    private function sign(string $id, array $payload): string
    {
        $material = $id.'|'.$payload['spa_id'].'|'.$payload['fingerprint'].'|'.$payload['start_at'].'|'.$payload['created_at'];

        return hash_hmac('sha256', $material, config('app.key'));
    }
}
