<?php

namespace Tests\Engine;

use App\Domain\Catalogue\CatalogueService;
use App\Domain\Catalogue\Presentation;
use App\Domain\Partner\OnboardingService;
use App\Models\Treatment;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Présentation des formules (composantes, durée totale, prix couple, inclus, extras, badge unique)
 * et infos pratiques : données dérivées sans toucher au moteur ni au devis.
 */
class PresentationTest extends EngineTestCase
{
    use RefreshDatabase;

    public function test_duration_label(): void
    {
        $this->assertSame('45 min', Presentation::duration(45));
        $this->assertSame('1h', Presentation::duration(60));
        $this->assertSame('1h45', Presentation::duration(105));
        $this->assertSame('2h05', Presentation::duration(125));
    }

    public function test_catalogue_exposes_components_total_duration_couple_price_included_and_extras(): void
    {
        $this->spa();
        $h = $this->type('hammam', 'Hammam');
        $m = $this->type('massage', 'Cabine', 'unit');
        $this->resource($h, 'Hammam', 6);
        $this->resource($m, 'Cabine 1', 1);
        $t = $this->treatment('rituel', 'Hammam + Massage', [['type' => 'hammam', 'duration' => 45], ['type' => 'massage', 'duration' => 60, 'offset' => 45]], 2, ['price_solo' => 650, 'price_couple' => 1200]);
        $t->steps()->where('position', 1)->update(['label' => 'Massage à l’argan']);
        $t->update(['included' => ['robe', 'tea', 'tea', 'bogus'], 'featured_badge' => 'signature']);
        $this->extra($t, 'Massage crânien', 125, 15);

        app()->setLocale('fr');
        $row = app(CatalogueService::class)->treatments($this->reload())->firstWhere('slug', 'rituel');

        $this->assertTrue($row['is_package']);
        $this->assertSame('1h45', $row['duration_label']);
        $this->assertSame('Hammam 45 min + Massage à l’argan 60 min', $row['components']);
        $this->assertSame(['Hammam', 'Massage à l’argan'], array_column($row['steps'], 'type'));
        $this->assertSame(1200.0, (float) $row['price_couple']);
        $this->assertSame(['Thé à la menthe', 'Peignoir'], $row['included']);
        $this->assertSame('Signature', $row['badge']);
        $this->assertSame(125.0, (float) $row['extras'][0]['price']);
        $this->assertSame(15, $row['extras'][0]['extra_min']);

        // Le devis est inchangé : prix couple total pour 2, extra par personne.
        $q = $this->quotes->fromRequest($this->spa, ['treatment' => $t->id, 'party' => 2, 'extras' => [['id' => $t->extras()->first()->id, 'qty' => 2]]]);
        $this->assertEquals(1200 + 2 * 125, $q['total']);
    }

    public function test_public_page_shows_presentation_blocks_and_practical_info_only_when_filled(): void
    {
        $this->spa();
        $h = $this->type('hammam', 'Hammam');
        $m = $this->type('massage', 'Cabine', 'unit');
        $this->resource($h, 'Hammam', 6);
        $this->resource($m, 'Cabine 1', 1);
        $t = $this->treatment('rituel', 'Hammam + Massage', [['type' => 'hammam', 'duration' => 45], ['type' => 'massage', 'duration' => 60, 'offset' => 45]], 2, ['price_solo' => 650, 'price_couple' => 1200]);
        $t->update(['included' => ['tea', 'pool'], 'featured_badge' => 'popular']);
        $this->extra($t, 'Massage crânien', 125, 15);

        $r = $this->get('/fr/spa/'.$this->spa->slug)->assertOk();
        $r->assertSee('Durée totale')->assertSee('1h45')->assertSee('Pour 2 personnes')->assertSee('1 200')
            ->assertSee('Inclus')->assertSee('Thé à la menthe')->assertSee('Accès piscine')
            ->assertSee('Ajouter à mon rituel')->assertSee('Massage crânien')->assertSee('+15 min')
            ->assertSee('La plus demandée')->assertDontSee('Infos pratiques');

        $this->spa->update(['practical_info' => ['gender' => 'mixed', 'languages' => ['fr', 'en'], 'children' => '', 'bring' => '  ', 'unknown' => 'x']]);
        $this->assertSame(['gender' => 'mixed', 'languages' => ['fr', 'en']], $this->spa->fresh()->practical_info);
        $this->get('/fr/spa/'.$this->spa->slug)->assertOk()->assertSee('Infos pratiques')->assertSee('Mixte')->assertSee('Français, Anglais');
        $this->get('/en/spa/'.$this->spa->slug)->assertOk()->assertSee('Practical info')->assertSee('Mixed')->assertSee('Add to my ritual')->assertSee('For 2 people');

        // Réservation : le tunnel reste en 4 étapes, avec la nouvelle présentation.
        $this->get('/fr/spa/'.$this->spa->slug.'/reserver?soin='.$t->id)->assertOk()->assertSee('Ajouter à mon rituel')->assertSee('Pour 2');
    }

    public function test_only_one_featured_badge_per_spa_is_enforced_by_the_model(): void
    {
        $this->spa();
        $h = $this->type('hammam', 'Hammam');
        $this->resource($h, 'Hammam', 6);
        $a = $this->treatment('a', 'A', [['type' => 'hammam', 'duration' => 45]]);
        $b = $this->treatment('b', 'B', [['type' => 'hammam', 'duration' => 45]]);

        $a->update(['featured_badge' => 'signature']);
        $b->update(['featured_badge' => 'popular']);
        $this->assertNull($a->fresh()->featured_badge);
        $this->assertSame('popular', $b->fresh()->featured_badge);
        $this->assertSame(1, Treatment::where('spa_id', $this->spa->id)->whereNotNull('featured_badge')->count());

        // Valeur hors vocabulaire → ignorée ; autre spa non affecté.
        $b->update(['featured_badge' => 'best']);
        $this->assertNull($b->fresh()->featured_badge);
        $other = $this->spa('autre-spa', 'Autre');
        $ot = $this->type('hammam', 'Hammam');
        $this->resource($ot, 'H', 4);
        $this->treatment('x', 'X', [['type' => 'hammam', 'duration' => 30]])->update(['featured_badge' => 'signature']);
        $a->fresh()->update(['featured_badge' => 'signature']);
        $this->assertSame(1, Treatment::where('spa_id', $other->id)->whereNotNull('featured_badge')->count());
        $this->assertSame('signature', $a->fresh()->featured_badge);
    }

    public function test_onboarding_saves_components_included_and_single_badge(): void
    {
        $spa = $this->spa();
        $svc = app(OnboardingService::class);
        $svc->saveTreatments($spa, [
            ['name_fr' => 'Hammam', 'category' => 'hammam', 'duration_min' => 45, 'price_solo' => 200, 'included' => ['tea'], 'featured' => false],
            ['name_fr' => 'Rituel', 'category' => 'ritual', 'duration_min' => 60, 'price_solo' => 650, 'price_couple' => 1200, 'included' => ['tea', 'robe'], 'featured' => true, 'featured_badge' => 'popular',
                'components' => [['kind' => 'hammam', 'duration_min' => 45, 'label' => 'Hammam & gommage'], ['kind' => 'massage', 'duration_min' => 60, 'label' => ''], ['kind' => 'nope', 'duration_min' => 10]]],
        ]);
        $r = $spa->treatments()->where('slug', 'rituel')->with('steps.resourceType')->firstOrFail();
        $this->assertSame(105, $r->duration_min);
        $this->assertSame(105, $r->computedDuration());
        $this->assertSame(['hammam', 'massage'], $r->steps->sortBy('position')->map(fn ($s) => $s->resourceType->slug)->values()->all());
        $this->assertSame([0, 45], $r->steps->sortBy('position')->pluck('offset_min')->values()->all());
        $this->assertSame('Hammam & gommage', $r->steps->sortBy('position')->first()->displayLabel());
        $this->assertSame('Cabine de massage', $r->steps->sortBy('position')->last()->displayLabel());
        $this->assertSame(['tea', 'robe'], $r->included);
        $this->assertSame('popular', $r->featured_badge);
        $this->assertSame(1, $spa->treatments()->whereNotNull('featured_badge')->count());

        // Re-sauvegarde sans mise en avant → badge retiré ; composantes re-synchronisées.
        $svc->saveTreatments($spa, [
            ['name_fr' => 'Rituel', 'category' => 'ritual', 'duration_min' => 60, 'price_solo' => 650, 'components' => [['kind' => 'hammam', 'duration_min' => 30], ['kind' => 'soin', 'duration_min' => 50]]],
        ]);
        $r = $spa->treatments()->where('slug', 'rituel')->firstOrFail();
        $this->assertNull($r->featured_badge);
        $this->assertNull($r->included);
        $this->assertSame(80, $r->duration_min);
        $this->assertSame(2, $r->steps()->count());
        $this->assertSame(0, $spa->treatments()->where('slug', 'hammam')->count());
    }
}
