<?php

namespace Tests\Engine;

use App\Domain\Booking\BookingException;
use App\Models\Allocation;
use App\Models\Booking;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

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

    public function test_expired_intent_and_idempotent_retry(): void
    {
        $p = $this->intent('11:00', $this->sel);
        Cache::forget('hl:intent:'.$p['intent']['id']);
        $r = $this->submit($p);
        $this->assertTrue(! $r['ok'] && $r['reason'] === 'intent_expired', 'Intent expiré (cache disparu) → refus : '.$r['error']);

        $p = $this->intent('10:00', $this->sel);
        $first = $this->submit($p);
        $this->assertTrue($first['ok'], 'Intent valide → réservation créée');
        $allocationCount = Allocation::where('booking_id', $first['booking']->id)->count();
        $eventCount = $first['booking']->events()->count();
        Mail::fake();

        $retry = $this->submit($p);

        $this->assertTrue($retry['ok'], 'La répétition du même formulaire retourne la réservation existante.');
        $this->assertSame($first['booking']->id, $retry['booking']->id);
        $this->assertSame(1, Booking::where('intent_id', $p['intent']['id'])->count());
        $this->assertSame($allocationCount, Allocation::where('booking_id', $first['booking']->id)->count());
        $this->assertSame($eventCount, $first['booking']->events()->count());
        Mail::assertNothingQueued();
    }

    public function test_retry_with_another_customer_does_not_disclose_the_existing_booking(): void
    {
        $prepared = $this->intent('10:00', $this->sel);
        $first = $this->submit($prepared);
        $this->assertTrue($first['ok']);

        $this->expectReason('intent_replayed', fn () => $this->bookings->confirmIntent(
            $this->spa, $prepared['intent']['token'], array_replace($this->customer, [
                'email' => 'another@example.test', 'phone' => '0611111111',
            ]),
        ), 'La même intention ne peut pas être revendiquée par un autre client');

        $this->assertSame(1, Booking::where('intent_id', $prepared['intent']['id'])->count());
        $this->assertSame($this->customer['email'], $first['booking']->fresh()->email);
    }

    public function test_retry_normalizes_customer_identity_without_creating_new_rows(): void
    {
        $customer = array_replace($this->customer, ['phone' => '+212 600 000 000']);
        $prepared = $this->intent('10:00', $this->sel);
        $first = $this->submit($prepared, $customer);
        $this->assertTrue($first['ok']);

        $retry = $this->submit($prepared, array_replace($customer, [
            'first_name' => ' Test ', 'last_name' => ' Client ',
            'email' => ' CLIENT@EXAMPLE.TEST ', 'phone' => '+212600000000',
        ]));

        $this->assertTrue($retry['ok'], $retry['error']);
        $this->assertSame($first['booking']->id, $retry['booking']->id);
        $this->assertSame(1, Booking::where('intent_id', $prepared['intent']['id'])->count());
    }

    public function test_tampered_token_cannot_use_the_idempotent_retry_path(): void
    {
        $prepared = $this->intent('10:00', $this->sel);
        $this->assertTrue($this->submit($prepared)['ok']);
        $token = $prepared['intent']['token'];
        $token = substr($token, 0, -1).(substr($token, -1) === 'a' ? 'b' : 'a');

        $this->expectReason('intent_tampered', fn () => $this->bookings->confirmIntent(
            $this->spa, $token, $this->customer,
        ), 'Même après création, la signature du jeton est obligatoire');
    }

    public function test_retry_after_cache_loss_still_returns_the_same_signed_booking(): void
    {
        $prepared = $this->intent('10:00', $this->sel);
        $first = $this->submit($prepared);
        $this->assertTrue($first['ok']);
        Cache::forget('hl:intent:'.$prepared['intent']['id']);

        $retry = $this->submit($prepared);

        $this->assertTrue($retry['ok'], $retry['error']);
        $this->assertSame($first['booking']->id, $retry['booking']->id);
        $this->assertSame(1, Booking::where('intent_id', $prepared['intent']['id'])->count());
    }

    public function test_confirmation_rechecks_current_minimum_lead_time(): void
    {
        $this->travelTo($this->at('09:00')->toMutable());
        $prepared = $this->intent('10:00', ['treatment' => $this->m->id, 'party' => 1]);
        $this->spa->update(['min_lead_minutes' => 120]);

        $this->expectReason('too_soon', fn () => $this->bookings->confirmIntent(
            $this->spa, $prepared['intent']['token'], $this->customer,
        ), 'La règle de délai du spa est relue lors de la confirmation');

        $this->assertSame(0, Booking::where('intent_id', $prepared['intent']['id'])->count());
        $this->assertSame(0, Allocation::where('spa_id', $this->spa->id)->count());
    }

    public function test_confirmation_rejects_a_slot_that_started_after_preparation(): void
    {
        $this->travelTo($this->at('09:59')->toMutable());
        $prepared = $this->intent('10:00', ['treatment' => $this->m->id, 'party' => 1]);
        $this->travelTo($this->at('10:00')->toMutable());

        $this->expectReason('too_soon', fn () => $this->bookings->confirmIntent(
            $this->spa, $prepared['intent']['token'], $this->customer,
        ), 'L’heure de rendez-vous ne peut pas être atteinte lors de la soumission');

        $this->assertSame(0, Booking::where('intent_id', $prepared['intent']['id'])->count());
    }

    public function test_inactive_extra_after_preparation_is_rejected_without_any_booking(): void
    {
        $prepared = $this->intent('10:00', $this->sel);
        $this->cr->update(['status' => 'inactive']);

        $this->expectReason('catalogue_changed', fn () => $this->bookings->confirmIntent(
            $this->spa, $prepared['intent']['token'], $this->customer,
        ), 'Extra inactif depuis le devis');

        $this->assertSame(0, Booking::where('intent_id', $prepared['intent']['id'])->count());
        $this->assertSame(0, Allocation::where('spa_id', $this->spa->id)->count());
    }

    public function test_deleted_extra_after_preparation_is_rejected_without_underallocating(): void
    {
        $prepared = $this->intent('10:00', $this->sel);
        $this->cr->delete();

        $this->expectReason('catalogue_changed', fn () => $this->bookings->confirmIntent(
            $this->spa, $prepared['intent']['token'], $this->customer,
        ), 'Extra supprimé depuis le devis');

        $this->assertSame(0, Booking::where('intent_id', $prepared['intent']['id'])->count());
        $this->assertSame(0, Allocation::where('spa_id', $this->spa->id)->count());
    }

    public function test_step_offset_change_requires_a_new_quote_even_when_total_duration_is_unchanged(): void
    {
        $prepared = $this->intent('10:00', ['treatment' => $this->hm->id, 'party' => 1]);
        $this->hm->steps()->orderBy('position')->first()->update(['offset_min' => 15]);

        $this->expectReason('price_changed', fn () => $this->bookings->confirmIntent(
            $this->spa, $prepared['intent']['token'], $this->customer,
        ), 'Un nouvel ordonnancement nécessite un nouveau devis');

        $this->assertSame(0, Booking::where('intent_id', $prepared['intent']['id'])->count());
    }

    public function test_retry_after_cancellation_returns_cancelled_booking_without_recreation(): void
    {
        $prepared = $this->intent('10:00', $this->sel);
        $first = $this->submit($prepared)['booking'];
        $this->assertNotNull($first);
        $this->bookings->cancel($first, 'client');

        $retry = $this->submit($prepared);

        $this->assertTrue($retry['ok'], $retry['error']);
        $this->assertSame($first->id, $retry['booking']->id);
        $this->assertSame('cancelled', $retry['booking']->status);
        $this->assertSame(1, Booking::where('intent_id', $prepared['intent']['id'])->count());
        $this->assertSame([], $this->activeAllocations($first));
    }
}
