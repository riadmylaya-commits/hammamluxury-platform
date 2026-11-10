<?php

namespace Tests\Engine;

use App\Domain\Catalogue\CatalogueService;
use App\Livewire\Site\BookingFlow;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/** Fiche et étape 1 : packages en premier, liste repliée après sélection, redéploiement à la demande, état conservé au retour. */
class TreatmentOrderAndSelectionTest extends BookingFlowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        URL::defaults(['locale' => 'fr']);
        app()->setLocale('fr');
        // Le massage est déclaré avant le package dans le catalogue de l'établissement.
        $this->m->update(['sort_order' => 0]);
        $this->hm->update(['sort_order' => 1]);
        $this->treatment('soin-visage', 'Soin visage éclat', [['type' => 'massage', 'duration' => 30]], 2)->update(['sort_order' => 2]);
    }

    public function test_catalogue_groups_packages_first_then_category_order(): void
    {
        $cats = app(CatalogueService::class)->treatments($this->spa->fresh())->pluck('category')->values()->all();
        $this->assertSame('ritual', $cats[0]);
        $ranks = array_map(fn ($c) => CatalogueService::categoryRank($c), $cats);
        $this->assertSame($ranks, collect($ranks)->sort()->values()->all(), 'les catégories doivent suivre CATEGORY_ORDER');
        $this->assertSame(['ritual', 'hammam', 'massage', 'face'], array_values(array_unique($cats)));

        $html = $this->get('/fr/spa/'.$this->spa->slug)->assertOk()->getContent();
        $this->assertLessThan(strpos($html, 'Hammams &amp; gommages'), strpos($html, 'Rituels &amp; Packages'));
        $this->assertLessThan(strpos($html, 'Massages'), strpos($html, 'Hammams &amp; gommages'));
        $this->assertLessThan(strpos($html, 'Soins du visage et du corps'), strpos($html, 'Massages'));
        $this->assertLessThan(strpos($html, 'Massage relaxant'), strpos($html, 'Hammam + Massage'));

        $this->get('/en/spa/'.$this->spa->slug)->assertOk()->assertSee('Rituals &amp; Packages', false)->assertSee('Face &amp; body treatments', false);
    }

    public function test_step_one_collapses_list_after_selection_and_keeps_choice_on_back(): void
    {
        $c = Livewire::test(BookingFlow::class, ['spa' => $this->spa])
            ->assertSet('treatment', null)->assertSet('showAll', false)
            ->assertSee('Choisissez un soin')->assertSee('Rituels &amp; Packages', false)->assertSee('Hammam + Massage')->assertSee('Massage relaxant')
            ->assertDontSee('Votre soin a bien été sélectionné');

        // Sélection : la liste se replie, l'encadré apparaît avec nom, durée, prix et lien de modification.
        $c->call('selectTreatment', $this->m->id)->assertSet('treatment', $this->m->id)->assertSet('showAll', false)
            ->assertSee('Votre soin a bien été sélectionné')->assertSee('Massage relaxant')->assertSee('400 MAD')->assertSee('1h')
            ->assertSee('Découvrir les autres soins et forfaits')->assertSee('Nombre de personnes')->assertSee('Continuer')
            ->assertDontSee('Hammam + Massage')->assertDontSee('Choisissez un soin');

        // Redéploiement puis nouveau choix : la liste se referme et le récapitulatif suit.
        $c->call('toggleList')->assertSet('showAll', true)->assertSee('Hammam + Massage')->assertSee('Masquer les autres soins et forfaits')
            ->call('selectTreatment', $this->hm->id)->assertSet('treatment', $this->hm->id)->assertSet('showAll', false)
            ->assertSee('Hammam + Massage')->assertSee('600 MAD')->assertDontSee('Massage relaxant');

        // Un identifiant inconnu est ignoré.
        $c->call('selectTreatment', 999999)->assertSet('treatment', $this->hm->id);

        // Plusieurs personnes avec soins différents : toujours possible, listes groupées par catégorie ; les extras de la personne 1 sont repris au retour en mode simple.
        $c->call('changeParty', 1)->assertSet('party', 2)->assertSee('Même soin pour tout le groupe')->assertSee('Un soin différent pour chaque personne')->assertSee('Votre sélection')->assertSee('2 personnes')
            ->call('setMode', 'each')->assertSet('advanced', true)->assertSee('Personne 2')->assertSee('Modifier le soin')->assertDontSee('<optgroup', false)
            ->call('openPicker', 1)->assertSet('picker', 1)->assertSee('Choisissez le soin de la personne 2')->assertSee('Rituels &amp; Packages', false)->assertSee('Valider ce soin')
            ->call('pickTreatment', 1, $this->m->id)->assertSet('participants.1.treatment', $this->m->id)
            ->call('closePicker')->assertSet('picker', null)->assertSee('Massage relaxant')->assertSee('1 000 MAD')
            ->call('toggleExtra', $this->cr->id, 0)->assertSet('participants.0.extras.'.$this->cr->id, 1)
            ->call('setMode', 'same')->assertSet('advanced', false)->assertSet('extras.'.$this->cr->id, 1)->assertSet('party', 2)->assertSet('treatment', $this->hm->id);

        // Retour depuis l'étape 2 : sélection conservée, liste toujours repliée.
        $c->call('next')->assertSet('step', 2)->assertSet('error', '')
            ->call('back')->assertSet('step', 1)->assertSet('treatment', $this->hm->id)->assertSet('showAll', false)
            ->assertSee('Votre soin a bien été sélectionné')->assertDontSee('Massage relaxant');
    }

    public function test_back_to_single_treatment_mode_clamps_party_to_treatment_limit(): void
    {
        $solo = $this->treatment('massage-solo', 'Massage solo', [['type' => 'massage', 'duration' => 30]], 1, ['price_solo' => 350]);

        Livewire::test(BookingFlow::class, ['spa' => $this->spa->fresh()])
            ->call('selectTreatment', $solo->id)->assertSet('party', 1)
            // « + » sur un soin individuel : passage automatique en « un soin par personne », ce soin pour chacun.
            ->call('changeParty', 1)->assertSet('party', 2)->assertSet('advanced', true)->assertSet('participants.1.treatment', $solo->id)->assertSee('Personne 2')->assertSee('700 MAD')
            ->call('changeParty', 1)->assertSet('party', 3)->assertSet('participants.2.treatment', $solo->id)
            ->call('setMode', 'same')->assertSet('advanced', false)->assertSet('party', 1)->assertSet('treatment', $solo->id)
            ->assertSee('350 MAD')->assertSeeHtml('wire:click="next"')->assertDontSeeHtml('wire:click="next" disabled');
    }

    public function test_fixed_party_packages_are_only_bookable_as_shared_treatment(): void
    {
        $duo = $this->treatment('duo-massage', 'Massage Duo 1h', [['type' => 'massage', 'duration' => 60]], 2, ['price_couple' => 900]);
        $duo->update(['party_min' => 2, 'party_max' => 2, 'price_solo' => null]);

        // Arrivée depuis la fiche (?soin=) : le nombre de personnes se cale d'emblée sur le forfait.
        Livewire::withQueryParams(['soin' => $duo->id])->test(BookingFlow::class, ['spa' => $this->spa->fresh()])->assertSet('party', 2)->assertSee('900 MAD');

        $c = Livewire::test(BookingFlow::class, ['spa' => $this->spa->fresh()])
            ->call('selectTreatment', $duo->id)->assertSet('party', 2)->assertSee('900 MAD')->assertSeeHtml('<b style="font-size:17px">Massage Duo</b>')
            ->call('setMode', 'each')->assertSet('advanced', true)->assertSet('participants.0.treatment', null)->assertSee('Soin à choisir')->assertSee('Choisissez un soin pour chaque personne.')
            ->call('openPicker', 0)->assertDontSee('Massage Duo')->assertSee('forfaits duo et famille')
            ->call('pickTreatment', 0, $duo->id)->assertSet('participants.0.treatment', null)
            ->call('pickTreatment', 0, $this->m->id)->call('pickTreatment', 1, $this->m->id)->call('closePicker')->assertSee('800 MAD')
            ->call('setMode', 'same')->assertSet('treatment', $this->m->id)->assertSet('party', 2)->assertSee('800 MAD');
    }

    public function test_step_one_texts_in_english(): void
    {
        URL::defaults(['locale' => 'en']);
        app()->setLocale('en');
        Livewire::withQueryParams(['soin' => $this->hm->id])->test(BookingFlow::class, ['spa' => $this->spa])
            ->assertSee('Your treatment has been selected')->assertSee('Discover the other treatments and packages')->assertDontSee('Massage relaxant')
            ->call('toggleList')->assertSee('Rituals &amp; Packages', false)->assertSee('Massage relaxant');
    }
}
