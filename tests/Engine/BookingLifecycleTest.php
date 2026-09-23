<?php

namespace Tests\Engine;

use App\Domain\Booking\BookingException;
use App\Mail\BookingMail;
use App\Models\Allocation;
use App\Models\Booking;
use App\Models\LedgerEntry;
use Illuminate\Support\Facades\Mail;

class BookingLifecycleTest extends BookingFlowTestCase
{
    public function test_confirm_intent_persists_booking_participants_allocations(): void
    {
        $p = $this->intent('10:00', ['treatment' => $this->hm->id, 'party' => 2, 'extras' => [$this->cr->id]]);
        $r = $this->submit($p);
        $b = $r['booking'];
        $this->assertTrue($r['ok'] && $b->id > 0 && str_starts_with($b->reference, 'HL'), 'Réservation insérée #'.($b?->reference).' '.$r['error']);
        $this->assertTrue($b->isWaiting() && 1350.0 === (float) $b->total && "{$this->day} 10:00" === $b->start_at->format('Y-m-d H:i') && '12:15' === $b->end_at->format('H:i'), 'Ligne : waiting, prix serveur 1350, 10:00 → 12:15');
        $this->assertTrue('Riad Test' === $b->hotel && 2 === $b->party && 135 === $b->duration_min && 1350.0 === (float) $b->quote['total'] && 15.0 === (float) $b->commission_pct && 202.5 === (float) $b->commission_amount, 'Coordonnées, hôtel, devis figé, commission 15 % = 202,50');
        $parts = $b->participants;
        $this->assertTrue(1 === $parts->count() && 2 === $parts[0]->party && $this->hm->id === $parts[0]->treatment_id && 135 === $parts[0]->duration_min && 1 === count($parts[0]->extras), 'Participants persistés : 1 ligne (groupe de 2), H+M, 135 min, 1 extra');
        $alloc = $b->allocations;
        $withP = $alloc->where('booking_participant_id', $parts[0]->id);
        $ends = $alloc->map(fn ($a) => $a->end_at->format('H:i'));
        $this->assertTrue(3 === $alloc->count() && 3 === $withP->count() && 2 === $ends->filter(fn ($e) => $e === '12:15')->count(), 'Allocations : hammam 10:00–10:45 + 2 cabines 10:45–12:15 (extra +15), toutes liées au participant '.$this->fmt($alloc));
        $delta = $b->expires_at->diffInMinutes(now(), true);
        $this->assertTrue($delta > 118 && $delta < 122, 'Expiration waiting = maintenant + 2 h ('.round($delta).' min)');
        $this->assertSame(['created', 'notified:created'], $b->events->pluck('type')->all(), 'Journal : création + notification');
        Mail::assertSent(BookingMail::class, fn ($m) => $m->event === 'created' && $m->audience === 'client' && $m->hasTo('client@example.test'));
        Mail::assertSent(BookingMail::class, fn ($m) => $m->event === 'created' && $m->audience === 'partner');
        Mail::assertSentCount(2);
    }

    public function test_advanced_mode_two_participants(): void
    {
        $p = $this->intent('17:30', ['participants' => [['treatment' => $this->hm->id, 'extras' => [$this->cr->id]], ['treatment' => $this->hs->id]]]);
        $r = $this->submit($p, ['first_name' => 'Test', 'last_name' => 'Client', 'email' => 'client@example.test', 'phone' => '0600000000']);
        $b = $r['booking'];
        $pp = $b->participants;
        $aa = $b->allocations;
        $this->assertTrue($r['ok'] && 2 === $pp->count() && $this->hm->id === $pp[0]->treatment_id && $this->hs->id === $pp[1]->treatment_id && 4 === $aa->count() && 2 === $aa->pluck('booking_participant_id')->unique()->count(), 'Mode avancé : 2 participants (H+M, H+Soin), 4 allocations (2 hammam, 1 cabine, 1 salle) réparties sur 2 participants '.$this->fmt($aa));
        $this->assertTrue(1275.0 === (float) $b->total && null === $b->hotel, 'Prix = devis serveur 1275, hôtel facultatif');
    }

    public function test_partner_accept_then_complete_creates_commission(): void
    {
        $b = $this->submit($this->intent('10:00', ['treatment' => $this->m->id, 'party' => 1]))['booking'];
        Mail::fake();
        $this->bookings->accept($b, 'partner', 'Bienvenue');
        $this->assertTrue($b->fresh()->isConfirmed() && null === $b->fresh()->expires_at && 'Bienvenue' === $b->fresh()->partner_note, 'Acceptation : confirmée, échéance retirée, note partenaire');
        $this->assertSame([], $this->bookings->expireWaiting(now()->addHours(3)), 'Réservation confirmée : non expirable');
        Mail::assertSentCount(2);
        try {
            $this->bookings->accept($b->fresh());
            $this->fail('Double acceptation');
        } catch (BookingException $e) {
            $this->assertSame(409, $e->status, 'Accepter une réservation déjà confirmée → 409');
        }
        $this->bookings->complete($b->fresh());
        $this->assertTrue('completed' === $b->fresh()->status && 1 === LedgerEntry::where('booking_id', $b->id)->count() && 60.0 === (float) LedgerEntry::where('booking_id', $b->id)->value('amount'), 'Prestation terminée : écriture de commission 15 % de 400 = 60');
    }

    public function test_partner_decline_releases_slot(): void
    {
        $sel = ['treatment' => $this->hs->id, 'party' => 1];
        $b = $this->submit($this->intent('16:00', $sel))['booking'];
        $this->assertNotContains('16:00', $this->availability($sel)['times'], 'Salle de soin occupée par la demande en attente');
        Mail::fake();
        $this->bookings->decline($b, 'partner', 'Fermeture exceptionnelle');
        $this->assertTrue('declined' === $b->fresh()->status && [] === $this->activeAllocations($b) && 2 === Allocation::where('booking_id', $b->id)->where('status', 'released')->count(), 'Refus : allocations libérées (historique conservé)');
        $this->assertContains('16:00', $this->availability($sel)['times'], 'Après refus : 16:00 redevenu disponible');
        Mail::assertSent(BookingMail::class, fn ($m) => $m->event === 'declined' && $m->audience === 'client');
    }

    public function test_client_cancel_and_double_cancel(): void
    {
        $sel = ['treatment' => $this->hm->id, 'party' => 2, 'extras' => [$this->cr->id]];
        $b = $this->submit($this->intent('10:00', $sel))['booking'];
        $this->bookings->cancel($b, 'client');
        $this->assertTrue('cancelled' === $b->fresh()->status && 'client' === $b->fresh()->cancelled_by && [] === $this->activeAllocations($b) && in_array('10:00', $this->availability($sel)['times'], true), 'Annulation client : allocations libérées, 10:00 à nouveau disponible');
        try {
            $this->bookings->cancel($b->fresh());
            $this->fail('Double annulation');
        } catch (BookingException $e) {
            $this->assertSame('status', $e->reason, 'Annuler une réservation déjà annulée → refus');
        }
    }

    public function test_manage_token_and_reference_unique(): void
    {
        $a = $this->submit($this->intent('10:00', ['treatment' => $this->m->id, 'party' => 1]))['booking'];
        $b = $this->submit($this->intent('10:00', ['treatment' => $this->m->id, 'party' => 1]))['booking'];
        $this->assertTrue($a->manage_token !== $b->manage_token && 48 === strlen($a->manage_token) && $a->reference !== $b->reference, 'Jeton de suivi et référence uniques');
        $this->assertSame($a->id, Booking::where('manage_token', $a->manage_token)->value('id'), 'Suivi par jeton');
    }
}
