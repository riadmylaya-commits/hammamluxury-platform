<?php

namespace Tests\Engine;

use App\Domain\Booking\BookingService;
use App\Domain\Booking\QuoteBuilder;
use App\Domain\Catalogue\PublicationChecklist;
use App\Models\Spa;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportSpaCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private const FILE = 'database/catalogues/mythic-oriental-spa.json';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(ReferenceSeeder::class);
    }

    public function test_mythic_catalogue_imports_and_is_idempotent(): void
    {
        $this->artisan('hl:import-spa', ['file' => base_path(self::FILE)])->assertSuccessful();

        $spa = Spa::where('slug', 'mythic-oriental-spa-marrakech')->firstOrFail();
        $this->assertSame('pending', $spa->status, 'Fiche importée en attente de validation Admin');
        $this->assertSame('approved', $spa->partner->status);
        $this->assertSame('partner', $spa->partner->user->role);
        $this->assertSame(55, $spa->treatments()->where('status', 'active')->count());
        $this->assertSame(7, $spa->hours()->count());
        $this->assertSame(570, $spa->hours()->first()->opens_min);
        $this->assertSame(1290, $spa->hours()->first()->closes_min);
        $this->assertSame(['hammam' => 6, 'massage' => 3, 'praticien' => 3], $spa->resources()->where('status', 'active')->with('type')->get()
            ->groupBy(fn ($r) => $r->type->slug)->map(fn ($g) => (int) $g->sum('capacity'))->sortKeys()->all());
        $this->assertSame(450.0, $spa->fresh()->price_from);

        $hmsv1 = $spa->treatments()->where('slug', 'like', 'hmsv1-%')->firstOrFail();
        $this->assertSame('ritual', $hmsv1->category);
        $this->assertSame(175, $hmsv1->duration_min);
        $this->assertSame(1520.0, $hmsv1->price_solo);
        $this->assertSame(4, $hmsv1->party_max);
        $steps = $hmsv1->steps()->orderBy('position')->with('resourceType')->get();
        $this->assertSame([['hammam', 35, 0, 0], ['massage', 140, 35, 1]], $steps->map(fn ($s) => [$s->resourceType->slug, $s->duration_min, $s->offset_min, $s->staff_per_person])->all());
        $this->assertSame(7, $hmsv1->extras()->count(), 'Extras de la section « hammam + massage + visage »');
        $this->assertSame(['RH' => 100.0, 'BA' => 120.0], ['RH' => $hmsv1->extras()->where('name_fr', 'like', 'Purification%')->value('price'), 'BA' => (float) $hmsv1->extras()->where('name_fr', 'like', 'Bain%')->value('price')]);

        $h1 = $spa->treatments()->where('slug', 'like', 'h1-%')->firstOrFail();
        $this->assertSame(6, $h1->party_max);
        $this->assertSame(2, $h1->extras()->count(), 'Hammam seul : rhassoul et bain uniquement');
        $this->assertSame(0, $spa->treatments()->where('slug', 'like', 'v1-%')->firstOrFail()->extras()->where('name_fr', 'like', 'Purification%')->count(), 'Pas de rhassoul sans hammam');

        $quote = QuoteBuilder::treatmentPrice($hmsv1, 2);
        $this->assertSame(3040.0, $quote['total'], 'Prix par personne : 2 × 1 520');

        $missing = PublicationChecklist::failures($spa);
        $this->assertCount(1, $missing, 'Seules les photos manquent : '.implode(' | ', $missing));
        $this->assertStringContainsStringIgnoringCase('photo', $missing[0]);

        $password = $spa->partner->user->password;
        $this->artisan('hl:import-spa', ['file' => base_path(self::FILE)])->assertSuccessful();
        $spa->refresh();
        $this->assertSame(55, $spa->treatments()->count(), 'Relance : aucun doublon de soin');
        $this->assertSame(1, User::where('email', 'no-reply@hammamluxury.com')->count());
        $this->assertSame($password, $spa->partner->user->fresh()->password, 'Relance : compte existant inchangé');
        $this->assertSame(3, $spa->resourceTypes()->count(), 'Relance : types de ressources (hammam, cabine, praticien) non dupliqués');
        $this->assertSame(7, $spa->hours()->count());

        $day = CarbonImmutable::now()->addDays(7)->next(CarbonImmutable::WEDNESDAY);
        $spa->update(['status' => 'published', 'published_at' => now()]);
        $booking = app(BookingService::class)->book($spa, $day->setTime(10, 0), [['treatment' => $hmsv1->id, 'party' => 2, 'extras' => []]],
            ['first_name' => 'Test', 'last_name' => 'Import', 'email' => 'import@example.test', 'phone' => '+212600000000']);
        $this->assertSame('waiting', $booking->status);
        $this->assertSame(3040.0, (float) $booking->total);
        $this->assertSame(['hammam', 'massage', 'praticien'], $booking->allocations()->with('resourceType')->get()->map(fn ($a) => $a->resourceType->slug)->unique()->sort()->values()->all(),
            'Hammam puis cabine + praticien(ne) alloués');
    }

    public function test_photos_are_imported_once_and_processed(): void
    {
        Storage::fake('public');
        $dir = sys_get_temp_dir().'/hl-import-'.uniqid();
        mkdir($dir);
        $img = imagecreatetruecolor(900, 700);
        imagejpeg($img, "$dir/image-15-scaled.jpg");
        file_put_contents("$dir/image-16-scaled.jpg", 'pas une image');

        $this->artisan('hl:import-spa', ['file' => base_path(self::FILE), '--photos-dir' => $dir, '--publish' => true])->assertSuccessful();
        $spa = Spa::where('slug', 'mythic-oriental-spa-marrakech')->firstOrFail();
        $this->assertSame(1, $spa->photos()->count(), 'Fichier illisible ignoré, image valide importée');
        $photo = $spa->photos()->first();
        $this->assertTrue($photo->is_cover);
        $this->assertMatchesRegularExpression('#^spas/'.$spa->id.'/[0-9a-f-]+\.jpg$#', $photo->path);
        Storage::disk('public')->assertExists($photo->path);
        $this->assertSame('published', $spa->status);

        $this->artisan('hl:import-spa', ['file' => base_path(self::FILE), '--photos-dir' => $dir])->assertSuccessful();
        $this->assertSame(1, $spa->photos()->count(), 'Relance : photos existantes conservées, pas de doublon');
        $this->assertSame('published', $spa->fresh()->status, 'Relance sans --publish : statut conservé');
    }

    public function test_invalid_file_is_rejected_without_side_effects(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'hl');
        file_put_contents($file, json_encode(['partner' => ['email' => 'x@example.test', 'company_name' => 'X'], 'spa' => ['slug' => 'x', 'name' => 'X', 'city' => 'Marrakech'],
            'treatments' => [['code' => 'A1', 'name_fr' => 'A', 'category' => 'massage', 'price_per_person' => 100, 'steps' => [['kind' => 'massage', 'duration_min' => 60]], 'extras' => ['INCONNU']]]]));
        $this->artisan('hl:import-spa', ['file' => $file])->expectsOutputToContain('extra inconnu')->assertFailed();
        $this->assertSame(0, Spa::count());
        $this->assertSame(0, User::count());
        $this->artisan('hl:import-spa', ['file' => '/nulle/part.json'])->assertFailed();
    }
}
