<?php

namespace Tests\Engine;

use App\Domain\Media\PhotoProcessor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

/** Contrôle strict des photos : type réel, dimensions minimales, redimensionnement + réencodage JPEG. */
class PhotoProcessorTest extends TestCase
{
    public function test_large_png_is_resized_and_reencoded_as_jpeg(): void
    {
        Storage::fake('public');
        $file = UploadedFile::fake()->image('grande.png', 3000, 2000);
        $path = PhotoProcessor::store($file);

        $this->assertStringEndsWith('.jpg', $path);
        Storage::disk('public')->assertExists($path);
        [$w, $h, $type] = getimagesize(Storage::disk('public')->path($path));
        $this->assertSame(PhotoProcessor::MAX_EDGE, $w);
        $this->assertSame((int) round(PhotoProcessor::MAX_EDGE * 2 / 3), $h);
        $this->assertSame(IMAGETYPE_JPEG, $type);
    }

    public function test_too_small_image_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PhotoProcessor::process(UploadedFile::fake()->image('petite.jpg', 400, 300)->getRealPath());
    }

    public function test_non_image_disguised_as_jpeg_is_rejected(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'hl');
        file_put_contents($tmp, '<?php echo "not an image";');
        $this->expectException(InvalidArgumentException::class);
        try {
            PhotoProcessor::process($tmp);
        } finally {
            @unlink($tmp);
        }
    }
}
