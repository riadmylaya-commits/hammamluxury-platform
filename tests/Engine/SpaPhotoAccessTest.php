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

    public function test_hidden_photo_is_never_public_but_stays_for_admin_and_owner(): void
    {
        $visible = $this->photo($this->spa);
        $hidden = $this->photo($this->spa);
        $hidden->update(['hidden_at' => now(), 'hidden_reason' => 'provenance à confirmer']);

        $this->get($visible->url())->assertOk();
        $this->get($hidden->url())->assertNotFound();
        $page = $this->get('/fr/spa/'.$this->spa->slug)->assertOk();
        $page->assertSee($visible->url(), false)->assertDontSee($hidden->url(), false);
        $this->assertSame([$visible->id], $this->spa->fresh()->visiblePhotos()->pluck('id')->all(), 'spaCard : la photo isolée est exclue');

        $this->actingAs($this->spa->partner->user)->get($hidden->url())->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        auth()->logout();
        $admin = User::create(['name' => 'Admin', 'email' => 'admin2@example.test', 'password' => 'secret-test', 'role' => 'admin', 'email_verified_at' => now()]);
        $this->actingAs($admin)->get($hidden->url())->assertOk();
        auth()->logout();

        $this->assertDatabaseHas('spa_photos', ['id' => $hidden->id]);
        $this->assertTrue(Storage::disk('local')->exists($hidden->path));
    }

    public function test_hidden_cover_falls_back_to_first_visible_photo(): void
    {
        $cover = $this->photo($this->spa);
        $cover->update(['is_cover' => true, 'hidden_at' => now()]);
        $other = $this->photo($this->spa);

        $this->assertSame($other->id, $this->spa->fresh()->coverPhoto()?->id);
    }

    public function test_spa_without_confirmed_cancellation_policy_shows_to_confirm(): void
    {
        $this->spa->update(['cancellation_hours' => 48]);
        $this->get('/fr/spa/'.$this->spa->slug)->assertOk()->assertSee('48 h')->assertDontSee('Conditions d’annulation à confirmer');
        $this->spa->update(['cancellation_hours' => null]);
        $this->get('/fr/spa/'.$this->spa->slug)->assertOk()->assertSee('Conditions d’annulation à confirmer')->assertDontSee('0 h');
        $this->get('/en/spa/'.$this->spa->slug)->assertOk()->assertSee('Cancellation terms to be confirmed.');
    }
}
