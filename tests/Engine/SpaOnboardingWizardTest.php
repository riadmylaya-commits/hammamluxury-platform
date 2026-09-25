<?php

namespace Tests\Engine;

use App\Domain\Geo\Geocoder;
use App\Domain\Geo\WebsiteUrl;
use App\Domain\Partner\OnboardingService;
use App\Filament\Partner\Pages\RegisterSpa;
use App\Mail\SpaStatusMail;
use App\Models\Amenity;
use App\Models\Category;
use App\Models\City;
use App\Models\Partner;
use App\Models\Spa;
use App\Models\User;
use Database\Seeders\ReferenceSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** Assistant « Référencer mon spa » : sauvegarde étape par étape, reprise, soumission, refus/publication, multi-établissements. */
class SpaOnboardingWizardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('partner'));
        Mail::fake();
        $this->user = User::create(['first_name' => 'Nadia', 'last_name' => 'Benali', 'email' => 'nadia@example.test', 'password' => 'secret-test', 'role' => 'partner', 'phone' => '+212661351989', 'email_verified_at' => now()]);
        $this->partner = Partner::create(['user_id' => $this->user->id, 'company_name' => 'Nadia SARL', 'status' => 'pending']);
        User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'secret-test', 'role' => 'admin', 'email_verified_at' => now()]);
        $this->actingAs($this->user);
    }

    private function next($test, int $step)
    {
        return $test->call('dispatchFormEvent', 'wizard::nextStep', 'data', $step);
    }

    public function test_full_wizard_creates_draft_step_by_step_then_submits(): void
    {
        $city = City::where('slug', 'marrakech')->firstOrFail();
        $cats = Category::whereIn('slug', ['hammam-traditionnel', 'massage'])->pluck('id')->all();
        $amen = Amenity::whereIn('slug', ['sauna', 'parking'])->pluck('id')->all();

        $t = Livewire::test(RegisterSpa::class);
        $t->assertFormSet(['first_name' => 'Nadia', 'company_name' => 'Nadia SARL']);

        // 1. compte
        $t->fillForm(['first_name' => 'Nadia', 'last_name' => 'Benali', 'company_name' => 'Hammam Nadia SARL', 'phone' => '+212661351989', 'whatsapp_same' => false, 'whatsapp' => '+33612345678']);
        $this->next($t, 0)->assertHasNoFormErrors();
        $this->assertSame('+33612345678', $this->user->fresh()->whatsapp);
        $this->assertSame('Hammam Nadia SARL', $this->partner->fresh()->company_name);
        $this->assertDatabaseCount('spas', 0);

        // 2. établissement → brouillon créé
        $t->fillForm(['name' => 'Hammam Nadia', 'category' => 'hammam', 'city_id' => $city->id, 'area' => 'Médina', 'address' => '12 derb Test', 'description_fr' => str_repeat('Un hammam traditionnel au cœur de la médina. ', 3), 'spa_phone' => '+212524000000', 'website' => 'www.hammam-nadia.ma', 'location' => ['lat' => 31.6295, 'lng' => -7.9811]]);
        $this->next($t, 1)->assertHasNoFormErrors();
        $spa = Spa::where('name', 'Hammam Nadia')->firstOrFail();
        $this->assertSame('https://www.hammam-nadia.ma', $spa->website);
        $this->assertSame(31.6295, $spa->lat);
        $this->assertSame(-7.9811, $spa->lng);
        $this->assertSame('draft', $spa->status);
        $this->assertSame(2, $spa->onboarding_step);
        $this->assertSame('hammam-nadia-marrakech', $spa->slug);
        $this->assertSame($city->id, $spa->city_id);
        $this->assertDatabaseHas('activity_logs', ['action' => 'spa.draft_created', 'subject_id' => $spa->id]);

        // 3. photos : minimum bloquant
        $t->fillForm(['photos' => ['spas/a.jpg', 'spas/b.jpg']]);
        $this->next($t, 2)->assertHasFormErrors(['photos']);
        $t->fillForm(['photos' => array_map(fn ($i) => "spas/x$i.jpg", range(1, 41))]);
        $this->next($t, 2)->assertHasFormErrors(['photos']);
        $this->assertSame(0, $spa->photos()->count());
        $paths = array_map(fn ($i) => "spas/p$i.jpg", range(1, 10));
        $t->fillForm(['photos' => $paths]);
        $this->next($t, 2)->assertHasNoFormErrors();
        $this->assertSame(10, $spa->photos()->count());
        $this->assertTrue($spa->photos()->where('path', 'spas/p1.jpg')->value('is_cover'));

        // 4. services
        $t->fillForm(['categories' => $cats, 'amenities' => $amen]);
        $this->next($t, 3)->assertHasNoFormErrors();
        $this->assertEqualsCanonicalizing($cats, $spa->categories()->pluck('categories.id')->all());
        $this->assertEqualsCanonicalizing($amen, $spa->amenities()->pluck('amenities.id')->all());

        // 5. soins
        $t->fillForm(['treatments' => [
            ['name_fr' => 'Hammam traditionnel', 'category' => 'hammam', 'duration_min' => 45, 'price_solo' => 250],
            ['name_fr' => 'Hammam + massage', 'category' => 'ritual', 'duration_min' => 90, 'price_solo' => 650, 'price_couple' => 1200],
        ]]);
        $this->next($t, 4)->assertHasNoFormErrors();
        $this->assertSame(2, $spa->treatments()->count());

        // 6. horaires + capacité → ressources et étapes auto
        $t->fillForm(['hours_every_day' => false, 'hours' => [['weekday' => 1, 'opens_min' => '10:00', 'closes_min' => '20:00'], ['weekday' => 6, 'opens_min' => '09:00', 'closes_min' => '22:00']], 'hammam_capacity' => 0, 'massage_cabins' => 0, 'treatment_rooms' => 0]);
        $this->next($t, 5)->assertHasFormErrors(['hammam_capacity']);
        $t->fillForm(['hammam_capacity' => 8, 'massage_cabins' => 2]);
        $this->next($t, 5)->assertHasNoFormErrors();
        $spa->refresh();
        $this->assertSame(2, $spa->hours()->count());
        $this->assertSame(600, $spa->hours()->where('weekday', 1)->value('opens_min'));
        $this->assertSame(3, $spa->resources()->where('status', 'active')->count());
        $this->assertSame(8, $spa->resources()->whereHas('type', fn ($q) => $q->where('slug', 'hammam'))->value('capacity'));
        $ritual = $spa->treatments()->where('category', 'ritual')->first();
        $this->assertSame(2, $ritual->steps()->count());
        $this->assertSame(90, (int) $ritual->steps()->sum('duration_min'));
        $this->assertSame(6, $spa->onboarding_step);

        // Rechargement : soins, horaires et capacités sont repris tels qu'enregistrés
        $reloaded = Livewire::test(RegisterSpa::class);
        $this->assertSame($spa->id, $reloaded->get('spaId'));
        $this->assertCount(2, $reloaded->get('data.treatments'));
        $this->assertSame('Hammam + massage', array_values($reloaded->get('data.treatments'))[1]['name_fr']);
        $this->assertSame('10:00', array_values($reloaded->get('data.hours'))[0]['opens_min']);
        $reloaded->assertFormSet(['hammam_capacity' => 8, 'massage_cabins' => 2, 'name' => 'Hammam Nadia']);

        // 7. récapitulatif + envoi
        $t->fillForm(['accept_terms' => true])->call('register')->assertHasNoFormErrors();
        $spa->refresh();
        $this->assertSame('pending', $spa->status);
        $this->assertNull($spa->onboarding_step);
        $this->assertNotNull($spa->submitted_at);
        $this->assertEquals(250, $spa->price_from);
        $this->assertDatabaseHas('activity_logs', ['action' => 'spa.submitted', 'subject_id' => $spa->id, 'user_id' => $this->user->id]);
        Mail::assertQueued(SpaStatusMail::class, fn (SpaStatusMail $m) => $m->event === 'submitted' && $m->hasTo('admin@example.test'));
    }

    public function test_draft_is_resumed_with_its_data_and_back_navigation_keeps_values(): void
    {
        $city = City::where('slug', 'agadir')->firstOrFail();
        $t = Livewire::test(RegisterSpa::class);
        $this->next($t, 0);
        $t->fillForm(['name' => 'Spa Océan', 'category' => 'spa', 'city_id' => $city->id, 'address' => 'Bd du Front de mer', 'description_fr' => str_repeat('Spa face à la mer avec piscine chauffée. ', 2), 'spa_phone' => '528000000']);
        $this->next($t, 1)->assertHasNoFormErrors();
        $spa = Spa::where('name', 'Spa Océan')->firstOrFail();

        // Retour arrière puis re-validation : la ville et l'adresse sont conservées, pas de doublon
        $t->call('dispatchFormEvent', 'wizard::previousStep', 'data', 1);
        $t->assertFormSet(['first_name' => 'Nadia', 'name' => 'Spa Océan', 'city_id' => $city->id]);
        $this->next($t, 0);
        $t->fillForm(['area' => 'Founty']);
        $this->next($t, 1)->assertHasNoFormErrors();
        $this->assertSame(1, Spa::count());
        $this->assertSame('Founty', $spa->fresh()->area);

        // Nouvelle visite : reprise du brouillon à l'étape suivante avec ses données
        $again = Livewire::test(RegisterSpa::class);
        $this->assertSame($spa->id, $again->get('spaId'));
        $again->assertFormSet(['name' => 'Spa Océan', 'area' => 'Founty', 'city_id' => $city->id, 'spa_phone' => '528000000']);
        $this->assertSame($spa->id, app(OnboardingService::class)->currentDraft($this->partner)->id);
    }

    public function test_service_refuses_more_than_max_photos(): void
    {
        $spa = $this->partner->spas()->create(['name' => 'Trop de photos', 'slug' => 'trop', 'city' => 'Fès', 'category' => 'spa', 'status' => 'draft', 'onboarding_step' => 2]);
        $this->expectException(ValidationException::class);
        app(OnboardingService::class)->savePhotos($spa, array_map(fn ($i) => "spas/x$i.jpg", range(1, 41)));
    }

    public function test_draft_survives_logout_and_login_and_back_forward_across_all_steps(): void
    {
        $city = City::where('slug', 'casablanca')->firstOrFail();
        $cats = Category::whereIn('slug', ['spa'])->pluck('id')->all();
        $t = Livewire::test(RegisterSpa::class);
        $this->next($t, 0);
        $t->fillForm(['name' => 'Spa Anfa', 'category' => 'spa', 'city_id' => $city->id, 'address' => '1 bd Anfa', 'description_fr' => str_repeat('Spa urbain moderne avec hammam et sauna. ', 2), 'spa_phone' => '522000000']);
        $this->next($t, 1)->assertHasNoFormErrors();
        Storage::fake('public');
        $photos = array_map(fn ($i) => "spas/c$i.jpg", range(1, 10));
        foreach ($photos as $p) {
            Storage::disk('public')->put($p, 'jpg');
        }
        $t->fillForm(['photos' => $photos]);
        $this->next($t, 2)->assertHasNoFormErrors();
        $t->fillForm(['categories' => $cats]);
        $this->next($t, 3)->assertHasNoFormErrors();
        $spa = Spa::where('name', 'Spa Anfa')->firstOrFail();
        $this->assertSame(4, $spa->onboarding_step);

        // Déconnexion puis reconnexion : reprise du même brouillon à l'étape 5 avec toutes les données
        auth()->logout();
        $this->get('/partenaire/new')->assertRedirect();
        $this->actingAs(User::find($this->user->id));
        $again = Livewire::test(RegisterSpa::class);
        $this->assertSame($spa->id, $again->get('spaId'));
        $again->assertFormSet(['name' => 'Spa Anfa', 'city_id' => $city->id, 'address' => '1 bd Anfa', 'categories' => $cats]);
        $this->assertEqualsCanonicalizing($photos, array_values($again->get('data.photos')));

        // Précédent jusqu'à l'étape 1 puis Suivant jusqu'au récapitulatif : rien n'est perdu, aucun doublon
        $again->fillForm(['treatments' => [['name_fr' => 'Massage relaxant', 'category' => 'massage', 'duration_min' => 60, 'price_solo' => 400]]]);
        $this->next($again, 4)->assertHasNoFormErrors();
        $again->fillForm(['hours_every_day' => false, 'hours' => [['weekday' => 2, 'opens_min' => '10:00', 'closes_min' => '19:00']], 'hammam_capacity' => 0, 'massage_cabins' => 1, 'treatment_rooms' => 0]);
        $this->next($again, 5)->assertHasNoFormErrors();
        foreach ([6, 5, 4, 3, 2, 1] as $s) {
            $again->call('dispatchFormEvent', 'wizard::previousStep', 'data', $s);
        }
        $again->assertFormSet(['first_name' => 'Nadia', 'name' => 'Spa Anfa', 'categories' => $cats, 'massage_cabins' => 1]);
        $this->assertEqualsCanonicalizing($photos, array_values($again->get('data.photos')));
        $this->assertSame('Massage relaxant', array_values($again->get('data.treatments'))[0]['name_fr']);
        $this->assertSame('10:00', array_values($again->get('data.hours'))[0]['opens_min']);
        foreach (range(0, 5) as $s) {
            $this->next($again, $s)->assertHasNoFormErrors();
        }
        $spa->refresh();
        $this->assertSame(1, Spa::count());
        $this->assertSame(10, $spa->photos()->count());
        $this->assertSame(1, $spa->treatments()->count());
        $this->assertSame(1, $spa->hours()->count());
        $this->assertSame(6, $spa->onboarding_step);
        $this->assertSame('draft', $spa->status);
    }

    public function test_submission_is_blocked_when_listing_incomplete(): void
    {
        $spa = $this->partner->spas()->create(['name' => 'Incomplet', 'slug' => 'incomplet', 'city' => 'Fès', 'category' => 'hammam', 'status' => 'draft', 'onboarding_step' => 6]);
        Livewire::test(RegisterSpa::class)->fillForm(['accept_terms' => true])->call('register');
        $this->assertSame('draft', $spa->fresh()->status);
        Mail::assertNothingQueued();
    }

    public function test_admin_refusal_and_publication_notify_partner_and_log(): void
    {
        $spa = $this->partner->spas()->create(['name' => 'À valider', 'slug' => 'a-valider', 'city' => 'Rabat', 'category' => 'spa', 'status' => 'pending', 'submitted_at' => now()]);
        $spa->update(['status' => 'draft', 'status_note' => 'Photos floues, adresse incomplète.']);
        OnboardingService::notifyDecision($spa, 'refused');
        Mail::assertQueued(SpaStatusMail::class, fn (SpaStatusMail $m) => $m->event === 'refused' && $m->hasTo('nadia@example.test'));
        $this->assertDatabaseHas('activity_logs', ['action' => 'spa.refused', 'subject_id' => $spa->id]);

        // Le refus rouvre l'assistant du partenaire sur le récapitulatif, avec le motif, et permet de renvoyer
        $spa->refresh();
        $this->assertSame(6, $spa->onboarding_step);
        $this->assertSame($spa->id, app(OnboardingService::class)->currentDraft($this->partner)->id);
        Livewire::test(RegisterSpa::class)->assertSee('Photos floues, adresse incomplète.')->assertSet('spaId', $spa->id);

        $spa->update(['status' => 'published', 'status_note' => null]);
        OnboardingService::notifyDecision($spa, 'published');
        Mail::assertQueued(SpaStatusMail::class, fn (SpaStatusMail $m) => $m->event === 'published' && $m->hasTo('nadia@example.test'));
        $this->assertDatabaseHas('activity_logs', ['action' => 'spa.published', 'subject_id' => $spa->id]);
    }

    public function test_partner_with_existing_spa_can_add_a_second_one_via_wizard(): void
    {
        $existing = $this->partner->spas()->create(['name' => 'Premier', 'slug' => 'premier', 'city' => 'Marrakech', 'category' => 'hammam', 'status' => 'published', 'published_at' => now()]);
        $city = City::where('slug', 'casablanca')->firstOrFail();

        $t = Livewire::test(RegisterSpa::class);
        $this->assertNull($t->get('spaId'));
        $this->next($t, 0);
        $t->fillForm(['name' => 'Second', 'category' => 'spa', 'city_id' => $city->id, 'address' => 'Anfa', 'description_fr' => str_repeat('Second établissement du groupe. ', 2), 'spa_phone' => '+212522000000']);
        $this->next($t, 1)->assertHasNoFormErrors();

        $this->assertSame(2, $this->partner->spas()->count());
        $this->assertSame('published', $existing->fresh()->status);
        $second = Spa::where('name', 'Second')->firstOrFail();
        $this->assertSame($this->partner->id, $second->partner_id);
        $this->assertSame('draft', $second->status);

        // Le brouillon repris est le second ; l'établissement publié n'est jamais proposé comme brouillon
        $this->assertSame($second->id, app(OnboardingService::class)->currentDraft($this->partner)->id);
        $this->assertNull(app(OnboardingService::class)->currentDraft($this->partner, $existing->id));
    }

    public function test_website_is_normalized_and_invalid_values_rejected(): void
    {
        $this->assertSame('https://riadmylaya.com', WebsiteUrl::normalize('riadmylaya.com'));
        $this->assertSame('https://www.riadmylaya.com', WebsiteUrl::normalize(' www.riadmylaya.com/ '));
        $this->assertSame('https://riadmylaya.com/spa', WebsiteUrl::normalize('http://riadmylaya.com/spa'));
        $this->assertNull(WebsiteUrl::normalize(''));
        $this->assertNull(WebsiteUrl::normalize('pas une url'));

        $city = City::where('slug', 'marrakech')->firstOrFail();
        $t = Livewire::test(RegisterSpa::class);
        $this->next($t, 0);
        $base = ['name' => 'Spa Web', 'category' => 'spa', 'city_id' => $city->id, 'address' => '1 rue Test', 'description_fr' => str_repeat('Spa test description assez longue. ', 2), 'spa_phone' => '+212524000000'];

        $t->fillForm($base + ['website' => 'pas une url']);
        $this->next($t, 1)->assertHasFormErrors(['website']);
        $this->assertDatabaseCount('spas', 0);

        $t->fillForm($base + ['website' => 'riadmylaya.com']);
        $this->next($t, 1)->assertHasNoFormErrors();
        $this->assertSame('https://riadmylaya.com', Spa::firstOrFail()->website);

        $again = Livewire::test(RegisterSpa::class);
        $again->assertFormSet(['website' => 'https://riadmylaya.com']);
    }

    public function test_every_day_hours_shortcut_creates_seven_rows_and_is_restored(): void
    {
        $spa = $this->draftAtStep(5);

        $t = Livewire::test(RegisterSpa::class);
        $t->assertFormSet(['hours_every_day' => true]);
        $t->fillForm(['hours_every_day' => true, 'every_opens' => '10:00', 'every_closes' => '09:00', 'hammam_capacity' => 4, 'massage_cabins' => 0, 'treatment_rooms' => 0]);
        $this->next($t, 5)->assertHasFormErrors(['every_closes']);
        $this->assertSame(0, $spa->hours()->count());

        $t->fillForm(['every_closes' => '20:00']);
        $this->next($t, 5)->assertHasNoFormErrors();
        $this->assertSame(7, $spa->hours()->count());
        $this->assertSame([0, 1, 2, 3, 4, 5, 6], $spa->hours()->orderBy('weekday')->pluck('weekday')->all());
        $this->assertSame([600], $spa->hours()->distinct()->pluck('opens_min')->all());
        $this->assertSame([1200], $spa->hours()->distinct()->pluck('closes_min')->all());
        $this->assertTrue($spa->fresh()->hasSameHoursEveryDay());

        $again = Livewire::test(RegisterSpa::class);
        $again->assertFormSet(['hours_every_day' => true, 'every_opens' => '10:00', 'every_closes' => '20:00']);

        // passage en mode manuel : lundi fermé, samedi plus long
        $again->fillForm(['hours_every_day' => false, 'hours' => [['weekday' => 1, 'opens_min' => '09:00', 'closes_min' => '19:00'], ['weekday' => 5, 'opens_min' => '09:00', 'closes_min' => '23:00']]]);
        $this->next($again, 5)->assertHasNoFormErrors();
        $this->assertSame(2, $spa->hours()->count());
        $this->assertFalse($spa->fresh()->hasSameHoursEveryDay());
        Livewire::test(RegisterSpa::class)->assertFormSet(['hours_every_day' => false]);
    }

    public function test_locate_action_geocodes_address_and_sets_marker(): void
    {
        Http::fake(fn ($request) => str_contains($request['q'], 'Nulle part')
            ? Http::response([])
            : Http::response([['lat' => '31.6300000', 'lon' => '-7.9800000', 'display_name' => 'Derb Test, Médina, Marrakech']]));
        $city = City::where('slug', 'marrakech')->firstOrFail();

        $t = Livewire::test(RegisterSpa::class);
        $this->next($t, 0);
        $t->fillForm(['address' => '12 derb Test', 'city_id' => $city->id]);
        $t->callFormComponentAction('location', 'locate');
        $t->assertFormSet(['location' => ['lat' => 31.63, 'lng' => -7.98]]);
        Http::assertSent(fn ($r) => str_contains($r['q'], '12 derb Test') && str_contains($r['q'], 'Marrakech') && $r['countrycodes'] === 'ma');

        $t->fillForm(['address' => 'Nulle part xyz']);
        $t->callFormComponentAction('location', 'locate')->assertNotified(__('partner.map_not_found'));
        $this->assertNull(Spa::first());
    }

    public function test_geocoder_falls_back_to_shorter_queries_for_full_addresses(): void
    {
        Http::fake(fn ($request) => $request['q'] === 'Rue de la Liberté, Guéliz, Marrakech'
            ? Http::response([['lat' => '31.6356373', 'lon' => '-8.0114897', 'display_name' => 'Rue de la Liberté, Guéliz, Marrakech, Maroc']])
            : Http::response([]));

        $hit = app(Geocoder::class)->search('Rue de la Liberté, Guéliz, Marrakech, Maroc', 'Marrakech');

        $this->assertSame(31.6356373, $hit['lat']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['q'] === 'Rue de la Liberté, Guéliz, Marrakech');
    }

    /** Brouillon prêt à l'étape donnée (1-based) pour tester une étape isolément. */
    private function draftAtStep(int $step): Spa
    {
        $spa = $this->partner->spas()->create(['name' => 'Spa Etape', 'slug' => 'spa-etape', 'city_id' => City::where('slug', 'marrakech')->value('id'), 'city' => 'Marrakech', 'category' => 'spa', 'status' => 'draft', 'onboarding_step' => $step - 1, 'address' => '1 rue Test', 'phone' => '+212524000000', 'description_fr' => str_repeat('Description. ', 5)]);

        return $spa;
    }
}
