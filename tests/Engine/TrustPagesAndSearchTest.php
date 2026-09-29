<?php

namespace Tests\Engine;

use App\Livewire\Site\BookingFlow;
use App\Livewire\Site\Contact;
use App\Mail\ContactMail;
use App\Models\Spa;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/** Session 1 : pages d'information FR/EN, formulaire Contact, galerie, recherche par date et tri par prix. */
class TrustPagesAndSearchTest extends BookingFlowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        URL::defaults(['locale' => 'fr']);
        app()->setLocale('fr');
    }

    public function test_information_pages_render_in_both_locales_and_footer_links_are_live(): void
    {
        $this->get('/fr/cgu')->assertOk()->assertSee('Conditions générales d’utilisation')->assertSee('Annulation et absence');
        $this->get('/en/cgu')->assertOk()->assertSee('Terms of use')->assertSee('Cancellation and no-show');
        $this->get('/fr/confidentialite')->assertOk()->assertSee('Politique de confidentialité')->assertSee('CNDP');
        $this->get('/en/confidentialite')->assertOk()->assertSee('Privacy policy');
        $this->get('/fr/faq')->assertOk()->assertSee('Questions fréquentes')->assertSee('Dois-je créer un compte');
        $this->get('/en/faq')->assertOk()->assertSee('Do I need an account');
        $this->get('/fr/contact')->assertOk()->assertSee('Votre message');
        $this->get('/en/contact')->assertOk()->assertSee('Your message');
        $this->get('/fr/inconnue')->assertNotFound();

        $this->get('/fr')->assertOk()
            ->assertSee('href="'.url('/fr/faq').'"', false)
            ->assertSee('href="'.url('/fr/contact').'"', false)
            ->assertSee('href="'.url('/fr/cgu').'"', false)
            ->assertSee('href="'.url('/fr/confidentialite').'"', false)
            ->assertDontSee('href="#"', false);

        Livewire::test(BookingFlow::class, ['spa' => $this->spa])->set('step', 3)->assertSeeHtml('href="'.url('/fr/cgu').'"');
    }

    public function test_contact_form_sends_mail_and_blocks_spam(): void
    {
        Mail::fake();
        config(['hl.contact_email' => 'admin@example.test']);

        Livewire::test(Contact::class)
            ->set('name', 'Client Test')->set('email', 'pas-un-email')->set('subject', 'Une réservation')->set('message', 'court')
            ->call('send')->assertHasErrors(['email', 'message'])->assertSet('sent', false)
            ->set('email', 'client@example.test')->set('message', 'Bonjour, je souhaite savoir si le hammam est ouvert le dimanche matin.')
            ->set('hp_started_at', now()->subMinute()->timestamp)
            ->call('send')->assertHasNoErrors()->assertSet('sent', true)->assertSee('Message envoyé');

        Mail::assertQueued(ContactMail::class, function (ContactMail $m) {
            return $m->hasTo('admin@example.test') && $m->hasReplyTo('client@example.test')
                && $m->envelope()->subject === '[Contact] Une réservation' && str_contains($m->body, 'dimanche');
        });

        Livewire::test(Contact::class)
            ->set('name', 'Robot')->set('email', 'bot@example.test')->set('subject', 'Autre')->set('message', str_repeat('spam ', 10))
            ->set('hp_website', 'http://spam.example')->set('hp_started_at', now()->subMinute()->timestamp)
            ->call('send')->assertSet('sent', true);
        Mail::assertQueued(ContactMail::class, 1);
    }

    public function test_search_by_date_keeps_only_open_venues_and_supports_price_sort(): void
    {
        $other = $this->secondSpa();
        $sunday = CarbonImmutable::parse($this->day)->next(CarbonImmutable::SUNDAY)->format('Y-m-d');
        $thursday = CarbonImmutable::parse($this->day)->addDay()->format('Y-m-d');

        $this->get('/fr/recherche')->assertOk()->assertSee($this->spa->name)->assertSee($other->name)->assertDontSee('Retirer la date');
        $this->get('/fr/recherche?date='.$this->day)->assertOk()->assertSee($this->spa->name)->assertSee($other->name)->assertSee('Établissements ouverts le')
            ->assertSee('href="'.url('/fr/spa/'.$this->spa->slug.'?date='.$this->day).'"', false);
        $this->get('/fr/recherche?date='.$sunday)->assertOk()->assertDontSee($this->spa->name)->assertSee($other->name);
        $this->get('/en/recherche?date='.$sunday.'&q=nulle-part')->assertOk()->assertSee('No venue is open on this date');

        $this->block('spa', $thursday.' 00:00:00', CarbonImmutable::parse($thursday)->addDay()->format('Y-m-d').' 00:00:00', 'holiday');
        $this->get('/fr/recherche?date='.$thursday)->assertOk()->assertDontSee($this->spa->name)->assertSee($other->name);
        $this->block('spa', $this->day.' 10:00:00', $this->day.' 12:00:00', 'maintenance');
        $this->get('/fr/recherche?date='.$this->day)->assertOk()->assertSee($this->spa->name, 'Un blocage partiel ne masque pas l’établissement');

        $this->get('/fr/recherche?date=2001-01-01')->assertOk()->assertSee($this->spa->name)->assertDontSee('Retirer la date');
        $this->get('/fr/recherche?date=n-importe-quoi')->assertOk()->assertSee($this->spa->name);

        $this->spa->refreshPriceFrom();
        $asc = $this->get('/fr/recherche?sort=price_asc')->assertOk()->getContent();
        $this->assertLessThan(strpos($asc, $this->spa->name), strpos($asc, $other->name), 'Prix croissant : 90 MAD avant 150 MAD');
        $desc = $this->get('/fr/recherche?sort=price_desc')->assertOk()->getContent();
        $this->assertLessThan(strpos($desc, $other->name), strpos($desc, $this->spa->name), 'Prix décroissant : 150 MAD avant 90 MAD');
        $this->get('/fr/recherche?sort=inconnu')->assertOk()->assertSee($this->spa->name);
    }

    public function test_search_date_is_carried_into_the_booking_flow(): void
    {
        $c = Livewire::withQueryParams(['date' => $this->day, 'soin' => $this->h->id])->test(BookingFlow::class, ['spa' => $this->spa])
            ->assertSet('date', $this->day)->assertSet('month', substr($this->day, 0, 7))
            ->call('next')->assertSet('step', 2)->assertSee('10:00');
        $this->assertNotEmpty($c->get('slots'));

        Livewire::withQueryParams(['date' => '2001-01-01'])->test(BookingFlow::class, ['spa' => $this->spa])->assertSet('date', '');

        $this->get('/fr/spa/'.$this->spa->slug.'?date='.$this->day)->assertOk()
            ->assertSee('href="'.url('/fr/spa/'.$this->spa->slug.'/reserver?date='.$this->day).'"', false);
    }

    public function test_spa_page_exposes_full_gallery_for_lightbox(): void
    {
        foreach (range(1, 7) as $i) {
            $this->spa->photos()->create(['path' => "spas/test/photo-$i.jpg", 'sort_order' => $i, 'caption_fr' => "Photo $i", 'caption_en' => "Picture $i"]);
        }
        $this->get('/fr/spa/'.$this->spa->slug)->assertOk()
            ->assertSee('id="hl-gallery"', false)->assertSee('Voir les 7 photos')->assertSee('photo-7.jpg')->assertSee('data-index="4"', false)->assertDontSee('data-index="5"', false)
            ->assertSee('hl-gallery.js');
        $this->get('/en/spa/'.$this->spa->slug)->assertOk()->assertSee('See all 7 photos')->assertSee('Picture 7');
    }

    private function secondSpa(): Spa
    {
        $main = $this->spa;
        $other = $this->spa('hl-test-open7', 'HL TEST Sept jours');
        $other->hours()->create(['weekday' => 6, 'opens_min' => 10 * 60, 'closes_min' => 18 * 60]);
        $other->treatments()->create(['slug' => 'gommage', 'name_fr' => 'Gommage', 'name_en' => 'Scrub', 'category' => 'hammam', 'price_solo' => 90, 'party_min' => 1, 'party_max' => 2, 'duration_min' => 30]);
        $other->refreshPriceFrom();
        $this->spa = $main;

        return $other->fresh();
    }
}
