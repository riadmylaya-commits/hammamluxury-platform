<?php

namespace Tests\Engine;

use App\Domain\Booking\BookingException;
use Illuminate\Support\Facades\Cache;

class IntentTest extends BookingFlowTestCase
{
    private array $sel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sel = ['treatment' => $this->hm->id, 'party' => 2, 'extras' => [$this->cr->id]];
    }

    private function expectReason(string $reason, callable $fn, string $label): void
    {
        try {
            $fn();
            $this->fail("$label : aucune exception");
        } catch (BookingException $e) {
            $this->assertSame($reason, $e->reason, "$label → refus $reason ({$e->getMessage()})");
        }
    }

    public function test_intent_refused_when_unavailable(): void
    {
        $this->expectReason('unavailable', fn () => $this->intent('10:00', ['treatment' => $this->m->id, 'party' => 3]), 'Intent massage 3 pers (capacité 2)');
        $this->expectReason('unavailable', fn () => $this->intent('13:00', $this->sel), 'Intent H+M couple à 13:00 (pause 14:00)');
    }

    public function test_valid_intent_and_tampering(): void
    {
        $p = $this->intent('10:00', $this->sel);
        $this->assertTrue($p['intent']['token'] !== '' && str_ends_with($p['end_at'], '12:15:00') && $p['quote']['total'] === 1350.0, 'Intent créé : fin 12:15, total serveur 1350');
        $this->assertTrue($p['intent']['expires_at'] > time() + 60 * (config('hl.intent_ttl_minutes') - 1), 'Intent : expiration = TTL configuré ('.config('hl.intent_ttl_minutes').' min)');

        $resolved = $this->intents->resolve($p['intent']['token'], $this->spa);
        $this->assertTrue($resolved['fingerprint'] === $p['quote']['fingerprint'] && $resolved['start_at'] === "{$this->day} 10:00:00", 'Intent valide résolu (signature, empreinte, début)');

        $tok = $p['intent']['token'];
        $bad = substr($tok, 0, -1).(substr($tok, -1) === 'a' ? 'b' : 'a');
        $this->expectReason('intent_tampered', fn () => $this->intents->resolve($bad, $this->spa), 'Intent altéré (signature)');
        $this->expectReason('intent_expired', fn () => $this->intents->resolve('abc', $this->spa), 'Intent mal formé');
        $other = $this->spa->replicate();
        $other->slug = 'other';
        $other->save();
        $this->expectReason('intent_spa', fn () => $this->intents->resolve($tok, $other), 'Intent lié à un autre établissement');

        $id = $p['intent']['id'];
        $payload = Cache::get('hl:intent:'.$id);
        $payload['start_at'] = "{$this->day} 10:30:00";
        Cache::put('hl:intent:'.$id, $payload, 300);
        $this->expectReason('intent_tampered', fn () => $this->intents->resolve($tok, $this->spa), 'Intent dont le contenu stocké a été modifié');
    }

    public function test_price_change_after_intent(): void
    {
        $p = $this->intent('10:00', $this->sel);
        $this->cr->update(['price' => 150]);
        $r = $this->submit($p);
        $this->assertTrue(! $r['ok'] && $r['reason'] === 'price_changed', 'Tarif modifié après l’intent → refus price_changed : '.$r['error']);
        $this->cr->update(['price' => 125]);
        $this->assertTrue($this->submit($p)['ok'], 'Tarif rétabli → même intent accepté');
    }

    public function test_expired_and_replayed_intent(): void
    {
        $p = $this->intent('11:00', $this->sel);
        Cache::forget('hl:intent:'.$p['intent']['id']);
        $r = $this->submit($p);
        $this->assertTrue(! $r['ok'] && $r['reason'] === 'intent_expired', 'Intent expiré (cache disparu) → refus : '.$r['error']);

        $p = $this->intent('10:00', $this->sel);
        $this->assertTrue($this->submit($p)['ok'], 'Intent valide → réservation créée');
        $r = $this->submit($p);
        $this->assertTrue(! $r['ok'] && $r['reason'] === 'intent_replayed', 'Rejeu du même intent → refus : '.$r['error']);
    }
}
