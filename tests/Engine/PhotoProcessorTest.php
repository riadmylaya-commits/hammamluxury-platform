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
        Storage::fake('local');
        $file = UploadedFile::fake()->image('grande.png', 3000, 2000);
        $path = PhotoProcessor::store($file);

        $this->assertStringEndsWith('.jpg', $path);
        Storage::disk('local')->assertExists($path);
        [$w, $h, $type] = getimagesize(Storage::disk('local')->path($path));
        $this->assertSame(PhotoProcessor::MAX_EDGE, $w);
        $this->assertSame((int) round(PhotoProcessor::MAX_EDGE * 2 / 3), $h);
        $this->assertSame(IMAGETYPE_JPEG, $type);
    }

    public function test_too_small_image_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PhotoProcessor::process(UploadedFile::fake()->image('petite.jpg', 400, 300)->getRealPath());
    }

    public function test_oversized_dimensions_are_rejected_before_gd_decodes_pixels(): void
    {
        $small = UploadedFile::fake()->image('small.png', 1, 1);
        $smallPng = file_get_contents($small->getRealPath());
        foreach ([[5000, 4000], [PhotoProcessor::MAX_INPUT_EDGE + 1, 600]] as [$width, $height]) {
            // Only patch IHDR and its CRC: no huge image is allocated by this test.
            $header = substr($smallPng, 0, 16).pack('NN', $width, $height).substr($smallPng, 24, 5);
            $png = $header.pack('N', crc32(substr($header, 12, 17))).substr($smallPng, 33);
            $tmp = tempnam(sys_get_temp_dir(), 'hl-large-image-');
            file_put_contents($tmp, $png);
            try {
                PhotoProcessor::process($tmp);
                $this->fail('Oversized image reached the decoder.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame(__('partner.photo_dimensions_too_large', ['edge' => PhotoProcessor::MAX_INPUT_EDGE, 'pixels' => 16]), $exception->getMessage());
            } finally {
                @unlink($tmp);
            }
        }
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
