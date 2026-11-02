<?php

namespace Tests\Engine;

use App\Domain\Partner\OwnerAssignmentService;
use App\Models\City;
use App\Models\Spa;
use App\Models\SpaPhoto;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Photos d'établissement hors dossier public : publiées → tout le monde ; sinon Admin (prévisualisation) et propriétaire seulement. */
class SpaPhotoAccessTest extends EngineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->spa();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function photo(Spa $spa, string $disk = 'local'): SpaPhoto
    {
        $path = 'spas/'.$spa->id.'/'.fake()->uuid().'.jpg';
        Storage::disk($disk)->put($path, 'jpeg-bytes');
        DB::table('spa_photo_uploads')->insert(['spa_id' => $spa->id, 'uploader_id' => null, 'path' => $path, 'created_at' => now(), 'updated_at' => now()]);

        return $spa->photos()->create(['path' => $path, 'sort_order' => 1]);
    }

    public function test_published_spa_photo_is_public_cached_and_served_from_private_disk(): void
    {
        $photo = $this->photo($this->spa);
        $this->assertSame(url('/photos/'.$photo->id), $photo->url());
        $this->assertStringNotContainsString('/storage/', $photo->url());

        config(['hl.noindex' => true]);
        $this->get($photo->url())->assertOk()->assertHeader('Cache-Control', 'immutable, max-age=31536000, public')
            ->assertHeader('Content-Type', 'image/jpeg');
        $this->assertContains($this->get('/storage/'.$photo->path)->status(), [403, 404], 'plus aucun accès direct au fichier');
        $this->get('/fr/spa/'.$this->spa->slug)->assertOk()->assertSee($photo->url(), false);
    }

    public function test_unpublished_spa_photo_is_404_except_for_admin_and_owner(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'secret-test', 'role' => 'admin', 'email_verified_at' => now()]);
        $draft = app(OwnerAssignmentService::class)->createSpa($admin, ['name' => 'Fiche privée', 'category' => 'hammam', 'city_id' => City::first()->id]);
        $photo = $this->photo($draft);

        $this->get($photo->url())->assertNotFound();
        $this->actingAs($this->spa->partner->user)->get($photo->url())->assertNotFound();
        auth()->logout();

        $this->actingAs($admin)->get($photo->url())->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($admin)->get('/fr/spa/'.$draft->slug)->assertOk()->assertSee($photo->url(), false);

        $draft->update(['status' => 'published', 'published_at' => now()]);
        auth()->logout();
        $this->get($photo->url())->assertOk();
    }

    public function test_owner_sees_own_unpublished_photo_but_not_another_spa_s(): void
    {
        $this->spa->update(['status' => 'pending', 'published_at' => null]);
        $own = $this->photo($this->spa);
        $this->actingAs($this->spa->partner->user)->get($own->url())->assertOk();

        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'secret-test', 'role' => 'admin', 'email_verified_at' => now()]);
        $other = app(OwnerAssignmentService::class)->createSpa($admin, ['name' => 'Autre fiche', 'category' => 'hammam', 'city_id' => City::first()->id]);
        auth()->logout();
        $otherPhoto = $this->photo($other);
        $this->actingAs($this->spa->partner->user)->get($otherPhoto->url())->assertNotFound();
    }

    public function test_privatize_command_moves_legacy_public_files_and_is_idempotent(): void
    {
        $legacy = $this->photo($this->spa, 'public');
        $this->get($legacy->url())->assertOk('un fichier non encore migré reste servi');

        $this->artisan('hl:photos-privatize', ['--dry-run' => true])->expectsOutputToContain('1 déplacée(s)')->assertSuccessful();
        Storage::disk('public')->assertExists($legacy->path);

        $this->artisan('hl:photos-privatize')->expectsOutputToContain('1 déplacée(s)')->assertSuccessful();
        Storage::disk('local')->assertExists($legacy->path);
        Storage::disk('public')->assertMissing($legacy->path);
        $this->get($legacy->url())->assertOk();

        $this->artisan('hl:photos-privatize')->expectsOutputToContain('0 déplacée(s), 1 déjà privée(s)')->assertSuccessful();
    }
}
