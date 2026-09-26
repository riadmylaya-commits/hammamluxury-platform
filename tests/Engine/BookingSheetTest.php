<?php

namespace Tests\Engine;

use App\Domain\Booking\QuoteBuilder;
use App\Filament\Partner\Resources\BookingResource\Pages\ViewBooking;
use App\Models\LedgerEntry;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/** Étape A — fiche réservation partenaire : devis figé, montant commissionnable, paiement, notes internes, passage automatique en terminée. */
class BookingSheetTest extends BookingFlowTestCase
{
    public function test_quote_freezes_steps_included_and_extras_with_commissionable_flag(): void
    {
        $this->hm->update(['included' => ['tea', 'robe']]);
        $this->hm->steps()->first()->update(['label' => 'Hammam & gommage']);
        $this->cr->update(['commissionable' => false]);
        $this->reload();

        $b = $this->submit($this->intent('10:00', ['treatment' => $this->hm->id, 'party' => 2, 'extras' => [$this->cr->id]]))['booking'];
        $line = $b->quote['lines'][0];

        $this->assertSame([['label' => 'Hammam & gommage', 'duration_min' => 45], ['label' => 'Cabine de massage', 'duration_min' => 75]], $line['steps'], 'Composantes figées avec libellé et durée');
        $this->assertSame(['Thé à la menthe', 'Peignoir'], $line['included'], 'Services inclus figés en clair');
        $this->assertSame(0, $line['extras'][0]['commissionable']);
        // couple 1100 + extra 125 × 2 pers = 1350 ; commissionnable = 1100
        $this->assertSame(1350.0, (float) $b->total);
        $this->assertSame(1100.0, (float) $b->commissionable_amount);
        $this->assertSame(165.0, (float) $b->commission_amount, '15 % de 1100, jamais du total');
        $this->assertSame(1185.0, $b->netForPartner());
        $this->assertSame('on_site', $b->payment_status);

        // Une modification ultérieure de la formule ne touche pas l'historique.
        $this->hm->update(['included' => []]);
        $this->hm->steps()->first()->update(['label' => 'Autre']);
        $this->cr->update(['price' => 999]);
        $this->assertSame('Hammam & gommage', $b->fresh()->quote['lines'][0]['steps'][0]['label']);
        $this->assertSame(125.0, (float) $b->fresh()->quote['lines'][0]['extras'][0]['unit_price']);

        $public = QuoteBuilder::publicView($b->quote);
        $this->assertArrayNotHasKey('commissionable', $public);
        $this->assertArrayNotHasKey('commissionable', $public['lines'][0]['extras'][0]);
    }

    public function test_confirmed_bookings_complete_automatically_after_end_and_ledger_uses_commissionable(): void
    {
        $b = $this->submit($this->intent('10:00', ['treatment' => $this->m->id, 'party' => 1]))['booking'];
        Mail::fake();
        $this->bookings->accept($b, 'partner');

        $this->assertSame([], $this->bookings->completePast($b->end_at->addMinutes(30)), 'Pas encore : délai de grâce non écoulé');
        $this->assertSame([$b->id], $this->bookings->completePast($b->end_at->addMinutes(61)));
        $this->assertSame('completed', $b->fresh()->status);
        $this->assertSame(60.0, (float) LedgerEntry::where('booking_id', $b->id)->value('amount'), '15 % de 400');
        $this->assertSame([], $this->bookings->completePast($b->end_at->addDay()), 'Idempotent');
        $this->assertSame('system', $b->events()->where('type', 'completed')->value('actor'));
    }

    public function test_partner_sheet_hides_email_shows_dial_code_notes_and_payment_without_complete_or_cancel(): void
    {
        $b = $this->submit($this->intent('10:00', ['treatment' => $this->hm->id, 'party' => 2, 'extras' => [$this->cr->id]]), [
            'first_name' => 'Claire', 'last_name' => 'Dupont', 'email' => 'claire.dupont@example.test', 'phone' => '+33612345678', 'hotel' => 'Riad Test',
        ])['booking'];
        Mail::fake();
        $this->bookings->accept($b, 'partner');

        $this->actingAs($this->spa->partner->user);
        Filament::setCurrentPanel(Filament::getPanel('partner'));
        Filament::setTenant($this->spa, true);

        $page = Livewire::test(ViewBooking::class, ['record' => $b->getRouteKey()])
            ->assertSee('Claire Dupont')->assertSee($b->reference)
            ->assertSee('France (+33)')->assertSee('tel:+33612345678')
            ->assertDontSee('claire.dupont@example.test')
            ->assertSee('Hammam + Massage')->assertSee('Massage crânien')->assertSee('+15 min')
            ->assertSee('Montant commissionnable')->assertSee('Net partenaire')->assertSee('À payer sur place')
            ->assertActionHidden('complete')->assertActionHidden('cancel')->assertActionVisible('payment');

        $page->callAction('payment', ['payment_status' => 'paid'])->assertHasNoActionErrors();
        $this->assertSame('paid', $b->fresh()->payment_status);
        $this->assertTrue($b->events()->where('type', 'payment:paid')->exists());

        $page->callAction('addNote', ['body' => 'Client préfère une masseuse femme'])->assertHasNoActionErrors();
        $this->assertSame(1, $b->notes()->count());
        $this->assertSame($this->spa->partner->user_id, $b->notes()->first()->user_id);
        $page->assertSee('Client préfère une masseuse femme');

        // Le client ne voit jamais les notes internes.
        $this->get(route('booking.show', ['locale' => 'fr', 'token' => $b->manage_token]))
            ->assertOk()->assertDontSee('masseuse femme')->assertDontSee('commissionnable');
    }
}
