<?php

namespace Tests\Engine;

use App\Domain\Booking\BookingException;
use App\Domain\Booking\QuoteBuilder;
use App\Domain\Catalogue\CatalogueService;

class QuoteTest extends BookingFlowTestCase
{
    public function test_formulas_solo_couple_group_and_fallback(): void
    {
        $q = $this->quote(['treatment' => $this->h->id, 'party' => 1]);
        $this->assertTrue($q['total'] === 150.0 && $q['lines'][0]['formula'] === 'solo' && $q['duration_min'] === 45, 'Devis hammam solo = 150, 45 min');
        $q = $this->quote(['treatment' => $this->h->slug, 'party' => 2]);
        $this->assertTrue($q['total'] === 350.0 && $q['lines'][0]['formula'] === 'couple', 'Devis hammam couple = 350 (formule couple, résolu par slug)');
        $q = $this->quote(['treatment' => $this->h->id, 'party' => 3]);
        $this->assertTrue($q['total'] === 450.0 && $q['lines'][0]['formula'] === 'group', 'Devis hammam groupe 3 = 3 × 150 = 450 (formule groupe)');
        $q = $this->quote(['treatment' => $this->m->id, 'party' => 3]);
        $this->assertTrue($q['total'] === 1200.0 && $q['lines'][0]['formula'] === 'solo', 'Devis massage 3 pers sans formule groupe = 3 × 400 = 1200 (fallback solo)');
        $this->assertSame(['group', 150.0], $this->h->formulaFor(3), 'Treatment::formulaFor cohérent avec QuoteBuilder');
    }

    public function test_extras_per_person_and_capped(): void
    {
        $q = $this->quote(['treatment' => $this->hm->id, 'party' => 2, 'extras' => [$this->cr->id]]);
        $this->assertTrue($q['total'] === 1350.0 && $q['duration_min'] === 135 && $q['party'] === 2, 'H+M couple + crânien (par personne) = 1100 + 2×125 = 1350, durée 120+15 = 135 min');
        $q = $this->quote(['treatment' => $this->hm->id, 'party' => 1, 'extras' => [['id' => $this->the->id, 'qty' => 5]]]);
        $this->assertTrue($q['total'] === 690.0 && $q['lines'][0]['extras'][0]['qty'] === 3 && $q['duration_min'] === 120, 'Extra « Thé » ×5 borné à max_qty 3, non par personne, sans durée : 600 + 3×30 = 690, 120 min');
        $q = $this->quote(['treatment' => $this->hm->id, 'party' => 1, 'extras' => [$this->cr->id => 1, $this->the->id => 2]]);
        $this->assertTrue($q['total'] === 785.0 && $q['duration_min'] === 135, 'Extras en map id⇒qté : 600 + 125 + 60 = 785');
        $q = $this->quote(['treatment' => $this->h->id, 'party' => 1, 'extras' => [$this->cr->id]]);
        $this->assertTrue($q['total'] === 150.0 && $q['lines'][0]['extras'] === [], 'Extra d’une autre prestation ignoré (150, sans extra)');
    }

    public function test_advanced_mode_different_treatment_per_person(): void
    {
        $q = $this->quote(['participants' => [['treatment' => $this->hm->id, 'extras' => [$this->cr->id]], ['treatment' => $this->hs->slug]]]);
        $this->assertTrue($q['total'] === 1275.0 && count($q['lines']) === 2 && $q['party'] === 2 && $q['duration_min'] === 135, 'Mode avancé : P1 H+M+crânien (725) + P2 H+Soin (550) = 1275, 2 personnes, durée max 135');
        $this->assertSame([1, 2], array_column($q['lines'], 'participant_no'), 'Participants numérotés 1 et 2');
        $this->assertStringContainsString('Hammam + Soin visage', QuoteBuilder::summaryText($q), 'Résumé lisible du devis');
    }

    public function test_rejections(): void
    {
        $q = $this->quote(['treatment' => $this->m->id, 'party' => 5]);
        $this->assertTrue(! $q['ok'] && str_contains($q['errors'][0], '4'), 'Massage pour 5 (max 4) → devis refusé : '.$q['errors'][0]);
        try {
            $this->quote(['treatment' => 999999, 'party' => 1]);
            $this->fail('Prestation inexistante acceptée');
        } catch (BookingException $e) {
            $this->assertSame('treatment', $e->reason, 'Prestation inexistante → refus');
        }
        try {
            $this->quote(['party' => 2]);
            $this->fail('Devis sans prestation accepté');
        } catch (BookingException $e) {
            $this->assertSame('empty', $e->reason, 'Aucune prestation → refus');
        }
        $this->hs->update(['status' => 'inactive']);
        try {
            $this->quote(['treatment' => $this->hs->id, 'party' => 1]);
            $this->fail('Prestation inactive acceptée');
        } catch (BookingException $e) {
            $this->assertSame('treatment', $e->reason, 'Prestation désactivée → refus');
        }
    }

    public function test_fingerprint_stable_and_sensitive(): void
    {
        $fa = $this->quote(['treatment' => $this->hm->id, 'party' => 2, 'extras' => [$this->cr->id]])['fingerprint'];
        $fb = $this->quote(['treatment' => $this->hm->id, 'party' => 2, 'extras' => [$this->cr->id]])['fingerprint'];
        $fc = $this->quote(['treatment' => $this->hm->id, 'party' => 2])['fingerprint'];
        $this->assertTrue($fa === $fb && $fa !== $fc, 'Empreinte de devis stable et sensible aux extras');
        $this->cr->update(['price' => 150]);
        $fd = $this->quote(['treatment' => $this->hm->id, 'party' => 2, 'extras' => [$this->cr->id]])['fingerprint'];
        $this->assertNotSame($fa, $fd, 'Empreinte sensible au changement de tarif');
    }

    public function test_public_catalogue(): void
    {
        $cat = app(CatalogueService::class);
        $list = $cat->treatments($this->spa);
        $pkg = $list->firstWhere('id', $this->hm->id);
        $this->assertTrue($list->count() === 4 && $pkg['is_package'] && $pkg['duration_min'] === 120 && count($pkg['extras']) === 2 && $pkg['price_from'] === 600.0, 'Catalogue : 4 prestations, package Hammam+Massage 120 min avec 2 extras, à partir de 600');
        $card = $cat->spaCard($this->spa->load('photos', 'hours'));
        $json = json_encode($card + ['treatments' => $list->all()]);
        $this->assertTrue(! str_contains($json, 'phone') && ! str_contains($json, 'email') && ! str_contains($json, 'document') && ! str_contains($json, 'company'), 'Catalogue : aucune coordonnée directe ni donnée juridique exposée');
        $this->assertSame(['09:00–14:00', '16:00–23:00'], $card['hours'][2], 'Horaires du mercredi exposés avec pause');

        $this->spa->update(['status' => 'pending']);
        $this->assertNull($cat->bookableSpas()->find($this->spa->id), 'Établissement non publié → absent du catalogue');
        try {
            $this->bookings->assertBookable($this->spa->fresh());
            $this->fail('Établissement non publié réservable');
        } catch (BookingException $e) {
            $this->assertSame(404, $e->status, 'Établissement non publié → 404 spa_not_bookable');
        }
        $this->spa->update(['status' => 'published']);
        $this->spa->partner->update(['status' => 'pending']);
        $this->assertNull($cat->bookableSpas()->find($this->spa->id), 'Partenaire non validé → établissement absent du catalogue');
    }

    public function test_fixed_party_formulas_are_priced_once_for_the_whole_party(): void
    {
        $duo = $this->treatment('duo', 'Massage duo', [['type' => 'massage', 'duration' => 60]], 2, ['price_solo' => null, 'price_couple' => 900]);
        $duo->update(['party_min' => 2]);
        $family = $this->treatment('famille', 'Soin famille 4 pers.', [['type' => 'hammam', 'duration' => 45]], 4, ['price_solo' => null, 'price_group' => 450]);
        $family->update(['party_min' => 4]);
        $this->reload();

        $q = $this->quote(['treatment' => $duo->id, 'party' => 2]);
        $this->assertTrue($q['ok'] && $q['total'] === 900.0 && $q['lines'][0]['formula'] === 'couple', 'Formule duo 900 MAD pour 2 : total 900, pas 1 800');
        $q = $this->quote(['treatment' => $family->id, 'party' => 4]);
        $this->assertTrue($q['ok'] && $q['total'] === 1800.0 && $q['lines'][0]['formula'] === 'group', 'Formule famille 1 800 MAD pour 4 : total 1 800');
        $this->assertFalse($this->quote(['treatment' => $duo->id, 'party' => 1])['ok'], 'Formule duo refusée pour 1 personne');
        $this->assertFalse($this->quote(['treatment' => $family->id, 'party' => 3])['ok'], 'Formule famille refusée pour 3 personnes');

        $cards = app(CatalogueService::class)->treatments($this->spa->fresh())->keyBy('id');
        $this->assertSame([2, 900.0, 450.0], [$cards[$duo->id]['fixed_party'], $cards[$duo->id]['fixed_party_total'], $cards[$duo->id]['price_from']], 'Fiche : « 900 MAD pour 2 personnes », soit 450 / pers.');
        $this->assertSame([4, 1800.0, 450.0], [$cards[$family->id]['fixed_party'], $cards[$family->id]['fixed_party_total'], $cards[$family->id]['price_from']]);
        $this->assertNull($cards[$this->h->id]['fixed_party'], 'Soin 1 à 6 personnes : pas de formule à effectif fixe');
    }
}
