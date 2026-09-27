<?php

namespace Tests\Engine;

use App\Models\Booking;

/** API JSON /api/v1 : catalogue, devis, disponibilité, intention signée, réservation invitée, suivi et annulation — mêmes services que le front. */
class ApiV1Test extends BookingFlowTestCase
{
    public function test_catalogue_hides_unpublished_and_contact_details(): void
    {
        $this->getJson('/api/v1/spas?q=marrakech')->assertOk()
            ->assertJsonPath('data.0.slug', $this->spa->slug)->assertJsonPath('meta.total', 1)
            ->assertJsonMissingPath('data.0.phone');
        $this->getJson('/api/v1/spas/'.$this->spa->slug)->assertOk()
            ->assertJsonPath('data.name', $this->spa->name)
            ->assertJsonCount(4, 'data.treatments')
            ->assertJsonPath('data.treatments.2.extras.0.extra_min', 15)
            ->assertJsonMissingPath('data.phone')->assertJsonMissingPath('data.address');
        $this->getJson('/api/v1/spas/'.$this->spa->slug.'?locale=en')->assertOk()->assertJsonPath('data.treatments.0.name', 'Hammam traditionnel');
        $this->spa->update(['status' => 'pending']);
        $this->getJson('/api/v1/spas/'.$this->spa->slug)->assertNotFound();
        $this->getJson('/api/v1/spas')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_quote_and_availability_follow_party_and_extras(): void
    {
        $sel = ['treatment' => $this->hm->id, 'party' => 2, 'extras' => [$this->cr->id => 1]];
        $this->postJson("/api/v1/spas/{$this->spa->slug}/quote", $sel)->assertOk()
            ->assertJsonPath('data.total', 1350)->assertJsonPath('data.duration_min', 135);
        $this->postJson("/api/v1/spas/{$this->spa->slug}/quote", ['treatment' => 999999, 'party' => 1])->assertStatus(422);

        $av = $this->postJson("/api/v1/spas/{$this->spa->slug}/availability", $sel + ['date' => $this->day])->assertOk()->json('data');
        $this->assertSame(135, $av['duration_min']);
        $this->assertContains('09:00', $av['times']);
        $this->assertNotContains('13:00', $av['times'], 'la coupure 14:00–16:00 du mercredi bloque la séquence');

        $three = $this->postJson("/api/v1/spas/{$this->spa->slug}/availability", ['treatment' => $this->hm->id, 'party' => 3, 'date' => $this->day])->assertOk()->json('data');
        $this->assertSame([], $three['times'], '2 cabines seulement → aucun créneau à 3');
        $this->assertNotSame('', $three['reason']);
        $this->postJson("/api/v1/spas/{$this->spa->slug}/availability", $sel)->assertStatus(422);
    }

    public function test_guest_booking_intent_confirm_track_and_cancel(): void
    {
        $sel = ['treatment' => $this->hm->id, 'party' => 2, 'extras' => [$this->cr->id => 1], 'date' => $this->day, 'time' => '10:00'];
        $intent = $this->postJson("/api/v1/spas/{$this->spa->slug}/booking-intent", $sel)->assertCreated()->json('data');
        $this->assertSame(1350.0, (float) $intent['quote']['total']);
        $this->assertStringEndsWith(' 12:15:00', $intent['end_at']);

        $this->postJson("/api/v1/spas/{$this->spa->slug}/bookings", ['intent' => $intent['intent']['token']])->assertStatus(422)->assertJsonValidationErrors(['first_name', 'email']);

        $res = $this->postJson("/api/v1/spas/{$this->spa->slug}/bookings", ['intent' => $intent['intent']['token']] + $this->customer)->assertCreated()->json('data');
        $this->assertSame('waiting', $res['status']);
        $this->assertSame(2, $res['party']);
        $this->assertCount(1, $res['participants']);
        $this->assertArrayNotHasKey('phone', $res['spa'], 'coordonnées masquées tant que non confirmé');
        $this->assertNotNull($res['expires_at']);

        $this->postJson("/api/v1/spas/{$this->spa->slug}/bookings", ['intent' => $intent['intent']['token']] + $this->customer)->assertStatus(409)->assertJsonPath('reason', 'intent_replayed');
        $this->postJson("/api/v1/spas/{$this->spa->slug}/bookings", ['intent' => 'x.'.str_repeat('a', 64)] + $this->customer)->assertStatus(410);

        $booking = Booking::where('reference', $res['reference'])->firstOrFail();
        $this->getJson('/api/v1/bookings/'.$res['manage_token'])->assertOk()->assertJsonPath('data.reference', $res['reference']);
        $this->getJson('/api/v1/bookings/nope')->assertNotFound();

        $this->bookings->accept($booking);
        $shown = $this->getJson('/api/v1/bookings/'.$res['manage_token'])->assertOk()->json('data');
        $this->assertSame('confirmed', $shown['status']);
        $this->assertArrayHasKey('phone', $shown['spa'], 'coordonnées visibles une fois confirmé');

        $this->postJson('/api/v1/bookings/'.$res['manage_token'].'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(0, $booking->fresh()->allocations()->where('status', 'active')->count());
        $this->postJson('/api/v1/bookings/'.$res['manage_token'].'/cancel')->assertStatus(409);
    }
}
