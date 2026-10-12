<?php

namespace Tests\Engine;

use App\Domain\Media\PhotoPathPolicy;
use App\Domain\Media\PhotoProcessor;
use App\Domain\Partner\OnboardingService;
use App\Filament\Partner\Pages\RegisterSpa;
use App\Models\Spa;
use App\Models\SpaPhoto;
use Database\Seeders\ReferenceSeeder;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

class PublicContentSecurityTest extends EngineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->spa();
        $this->actingAs($this->spa->partner->user);
        Storage::fake('public');
    }

    public function test_processed_upload_is_bound_to_spa_and_can_be_attached(): void
    {
        $path = PhotoProcessor::storeForSpa(UploadedFile::fake()->image('photo.png', 800, 600), $this->spa);
        $this->assertDatabaseHas('spa_photo_uploads', ['spa_id' => $this->spa->id, 'uploader_id' => auth()->id(), 'path' => $path]);
        app(OnboardingService::class)->savePhotos($this->spa, [$path]);
        $this->assertDatabaseHas('spa_photos', ['spa_id' => $this->spa->id, 'path' => $path]);
    }

    public function test_filename_prefix_and_existing_file_do_not_prove_ownership(): void
    {
        $this->actingAs($this->spa->partner->user);
        $path = 'spas/'.$this->spa->id.'/forged.jpg';
        Storage::disk('public')->put($path, 'not a server-processed upload');
        $this->expectException(ValidationException::class);
        $this->spa->photos()->create(['path' => $path]);
    }

    public function test_one_invalid_photo_does_not_delete_existing_gallery(): void
    {
        $path = PhotoProcessor::storeForSpa(UploadedFile::fake()->image('photo.jpg', 800, 600), $this->spa);
        app(OnboardingService::class)->savePhotos($this->spa, [$path]);
        try {
            app(OnboardingService::class)->savePhotos($this->spa, ['https://example.test/new.jpg']);
            $this->fail('New external URL accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('data.photos', $exception->errors());
        }
        $this->assertSame([$path], $this->spa->photos()->pluck('path')->all());
    }

    public function test_partner_cannot_attach_another_spas_uploaded_path(): void
    {
        $other = $this->spa('other-photo-spa');
        $this->actingAs($other->partner->user);
        $path = PhotoProcessor::storeForSpa(UploadedFile::fake()->image('other.jpg', 800, 600), $other);
        $target = Spa::where('slug', 'hl-test-spa')->firstOrFail();
        $this->actingAs($target->partner->user);
        $this->expectException(ValidationException::class);
        $target->photos()->create(['path' => $path]);
    }

    public function test_upload_cannot_be_registered_for_another_partners_spa(): void
    {
        $owner = auth()->user();
        $other = $this->spa('other-owner-spa');
        $this->actingAs($owner);
        $this->expectException(ValidationException::class);
        PhotoProcessor::storeForSpa(UploadedFile::fake()->image('photo.jpg', 800, 600), $other);
    }

    public function test_safe_legacy_external_photo_is_retained_but_not_transferable(): void
    {
        $legacy = 'https://images.example.test/photo.jpg';
        // Represents a row persisted before this correction, not a new partner submission.
        DB::table('spa_photos')->insert(['spa_id' => $this->spa->id, 'path' => $legacy, 'sort_order' => 0, 'is_cover' => true]);
        app(OnboardingService::class)->savePhotos($this->spa, [$legacy]);
        $this->assertSame($legacy, $this->spa->photos()->first()->url());
        $other = $this->spa('legacy-target-spa');
        $this->actingAs($other->partner->user);
        $this->expectException(ValidationException::class);
        $other->photos()->create(['path' => $legacy]);
    }

    public function test_dangerous_paths_and_urls_are_rejected_and_legacy_value_is_not_rendered(): void
    {
        foreach ([
            'javascript:alert(1)', 'data:image/svg+xml,<svg onload=alert(1)>', '//evil.test/photo.jpg',
            'spas/../private.jpg', 'spas/./photo.jpg', 'spas/..', 'spas/photo.jpg" onerror="alert(1)',
            "spas/photo.jpg\n", 'https://images.test/a.jpg" onerror="alert(1)',
            'https://user:password@images.test/a.jpg',
        ] as $path) {
            $this->assertFalse(PhotoPathPolicy::isSafe($path), $path);
            $this->assertSame('', (new SpaPhoto(['path' => $path]))->url());
            try {
                $this->spa->photos()->create(['path' => $path]);
                $this->fail('Unsafe photo accepted: '.$path);
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('path', $exception->errors());
            }
        }
        $this->assertSame(0, $this->spa->photos()->count());
    }

    public function test_livewire_existing_string_cannot_bypass_upload_ownership(): void
    {
        $this->seed(ReferenceSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('partner'));
        $this->spa->update(['status' => 'draft', 'onboarding_step' => 2]);
        $forged = 'spas/'.$this->spa->id.'/forged.jpg';
        Storage::disk('public')->put($forged, 'file merely exists');
        $paths = array_fill(0, (int) config('hl.min_photos'), $forged);
        $test = Livewire::test(RegisterSpa::class)->fillForm(['photos' => $paths]);
        $test->call('dispatchFormEvent', 'wizard::nextStep', 'data', 2)->assertHasFormErrors(['photos']);
        $this->assertSame(0, $this->spa->photos()->count());
    }
}
