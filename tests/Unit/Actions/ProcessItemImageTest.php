<?php

namespace Tests\Unit\Actions;

use App\Actions\ProcessItemImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProcessItemImageTest extends TestCase
{
    public function test_creates_two_bounded_webp_derivatives_without_upscaling_and_keeps_alpha(): void
    {
        config(['inventory.images_max_decoded_pixels' => 40_000_000]);
        $image = imagecreatetruecolor(120, 80);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);
        ob_start();
        imagepng($image);
        $contents = ob_get_clean();
        imagedestroy($image);

        $derivatives = (new ProcessItemImage)->process(UploadedFile::fake()->createWithContent('small.png', $contents));
        $this->assertSame(2, count($derivatives));
        $this->assertSame([120, 80], [$derivatives['thumbnail']['width'], $derivatives['thumbnail']['height']]);
        $this->assertSame([120, 80], [$derivatives['display']['width'], $derivatives['display']['height']]);
        $this->assertSame('image/webp', $derivatives['thumbnail']['mime_type']);
        $decoded = imagecreatefromstring($derivatives['display']['contents']);
        $alpha = (imagecolorat($decoded, 4, 4) >> 24) & 0x7F;
        imagedestroy($decoded);
        $this->assertGreaterThanOrEqual(120, $alpha);
    }

    public function test_resizes_large_images_to_the_thumbnail_and_display_bounds(): void
    {
        $image = imagecreatetruecolor(4000, 3000);
        ob_start();
        imagepng($image);
        $contents = ob_get_clean();
        imagedestroy($image);

        $derivatives = (new ProcessItemImage)->process(UploadedFile::fake()->createWithContent('large.png', $contents));
        $this->assertSame([320, 240], [$derivatives['thumbnail']['width'], $derivatives['thumbnail']['height']]);
        $this->assertSame([1440, 1080], [$derivatives['display']['width'], $derivatives['display']['height']]);
    }

    public function test_normalizes_every_jpeg_exif_orientation_and_removes_metadata(): void
    {
        $expectedSourceCorners = [
            1 => ['red', 'green', 'blue', 'yellow'],
            2 => ['green', 'red', 'yellow', 'blue'],
            3 => ['yellow', 'blue', 'green', 'red'],
            4 => ['blue', 'yellow', 'red', 'green'],
            5 => ['red', 'blue', 'green', 'yellow'],
            6 => ['blue', 'red', 'yellow', 'green'],
            7 => ['yellow', 'green', 'blue', 'red'],
            8 => ['green', 'yellow', 'red', 'blue'],
        ];

        foreach ($expectedSourceCorners as $orientation => $expectedCorners) {
            $jpeg = $this->jpegWithOrientation($orientation);
            $derivative = (new ProcessItemImage)->process(UploadedFile::fake()->createWithContent('orientation.jpg', $jpeg))['display'];
            $image = imagecreatefromstring($derivative['contents']);
            $points = [
                [2, 2],
                [imagesx($image) - 3, 2],
                [2, imagesy($image) - 3],
                [imagesx($image) - 3, imagesy($image) - 3],
            ];

            foreach ($points as $index => [$x, $y]) {
                $this->assertSame($expectedCorners[$index], $this->nearestColor(imagecolorat($image, $x, $y)), "Incorrect corner for EXIF orientation {$orientation}.");
            }

            $this->assertStringNotContainsString('Exif', $derivative['contents']);
            imagedestroy($image);
        }
    }

    public function test_rejects_animated_and_corrupt_images(): void
    {
        $png = $this->png(20, 20);
        $animated = $this->insertPngChunk($png, 'acTL', pack('N2', 2, 0));

        try {
            (new ProcessItemImage)->process(UploadedFile::fake()->createWithContent('animated.png', $animated));
            $this->fail('Animated PNG was accepted.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Animated images', $exception->errors()['image'][0]);
        }

        $this->expectException(ValidationException::class);
        (new ProcessItemImage)->process(UploadedFile::fake()->createWithContent('corrupt.png', 'not an image'));
    }

    public function test_rejects_images_that_exceed_the_decoded_pixel_limit(): void
    {
        $png = $this->png(10, 10);
        $png = substr_replace($png, pack('N2', 10_000, 5_000), 16, 8);
        $ihdr = 'IHDR'.substr($png, 16, 13);
        $png = substr_replace($png, pack('H*', hash('crc32b', $ihdr)), 29, 4);

        $this->expectException(ValidationException::class);
        (new ProcessItemImage)->process(UploadedFile::fake()->createWithContent('oversized.png', $png));
    }

    private function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);
        $contents = ob_get_clean();
        imagedestroy($image);

        return $contents;
    }

    private function insertPngChunk(string $png, string $type, string $data): string
    {
        $chunk = pack('N', strlen($data)).$type.$data.pack('H*', hash('crc32b', $type.$data));
        $idatPosition = strpos($png, 'IDAT');

        return substr($png, 0, $idatPosition - 4).$chunk.substr($png, $idatPosition - 4);
    }

    private function jpegWithOrientation(int $orientation): string
    {
        $image = imagecreatetruecolor(60, 40);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 0, 0));
        imagefilledrectangle($image, 30, 0, 59, 19, imagecolorallocate($image, 0, 255, 0));
        imagefilledrectangle($image, 0, 20, 29, 39, imagecolorallocate($image, 0, 0, 255));
        imagefilledrectangle($image, 30, 20, 59, 39, imagecolorallocate($image, 255, 255, 0));
        ob_start();
        imagejpeg($image, null, 100);
        $jpeg = ob_get_clean();
        imagedestroy($image);

        $tiff = "II\x2A\x00\x08\x00\x00\x00\x01\x00\x12\x01\x03\x00\x01\x00\x00\x00".pack('v', $orientation)."\x00\x00\x00\x00\x00\x00";
        $payload = "Exif\x00\x00".$tiff;
        $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
    }

    private function nearestColor(int $pixel): string
    {
        $red = ($pixel >> 16) & 0xFF;
        $green = ($pixel >> 8) & 0xFF;
        $blue = $pixel & 0xFF;
        $colors = [
            'red' => [255, 0, 0],
            'green' => [0, 255, 0],
            'blue' => [0, 0, 255],
            'yellow' => [255, 255, 0],
        ];
        $distances = [];

        foreach ($colors as $name => [$expectedRed, $expectedGreen, $expectedBlue]) {
            $distances[$name] = ($red - $expectedRed) ** 2 + ($green - $expectedGreen) ** 2 + ($blue - $expectedBlue) ** 2;
        }

        asort($distances);

        return array_key_first($distances);
    }
}
