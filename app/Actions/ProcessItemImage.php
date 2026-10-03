<?php

namespace App\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class ProcessItemImage
{
    /**
     * @return array{
     *     thumbnail: array{contents: string, mime_type: string, width: int, height: int},
     *     display: array{contents: string, mime_type: string, width: int, height: int}
     * }
     */
    public function process(UploadedFile $file): array
    {
        $contents = file_get_contents($file->getRealPath());
        if ($contents === false) {
            throw ValidationException::withMessages(['image' => 'The uploaded image could not be read.']);
        }

        $imageInfo = @getimagesizefromstring($contents);
        if ($imageInfo === false || ! in_array($imageInfo['mime'] ?? null, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw ValidationException::withMessages(['image' => 'The file is not a supported JPEG, PNG, or WebP image.']);
        }

        $width = (int) $imageInfo[0];
        $height = (int) $imageInfo[1];
        if ($width < 1 || $height < 1 || $width * $height > (int) config('inventory.images_max_decoded_pixels')) {
            throw ValidationException::withMessages(['image' => 'This photo is too large to process safely. Choose an image with 40 megapixels or fewer.']);
        }

        $this->rejectAnimation($contents, $imageInfo['mime']);

        $source = @imagecreatefromstring($contents);
        if ($source === false) {
            throw ValidationException::withMessages(['image' => 'The uploaded image is corrupt or cannot be decoded.']);
        }

        if ($imageInfo['mime'] === 'image/jpeg') {
            $source = $this->applyExifOrientation($source, $file->getRealPath());
        }

        imagealphablending($source, false);
        imagesavealpha($source, true);

        $thumbnail = $this->encodeDerivative($source, 320, 320);
        $display = $this->encodeDerivative($source, 1920, 1080);
        imagedestroy($source);

        return ['thumbnail' => $thumbnail, 'display' => $display];
    }

    private function rejectAnimation(string $contents, string $mimeType): void
    {
        $animated = match ($mimeType) {
            'image/png' => $this->pngHasAnimationChunk($contents),
            'image/webp' => $this->webpHasAnimationChunk($contents),
            default => false,
        };

        if ($animated) {
            throw ValidationException::withMessages(['image' => 'Animated images are not supported. Upload a still JPEG, PNG, or WebP photo.']);
        }
    }

    private function pngHasAnimationChunk(string $contents): bool
    {
        if (substr($contents, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            return false;
        }

        $length = strlen($contents);
        for ($offset = 8; $offset + 12 <= $length;) {
            $chunkLength = unpack('Nlength', substr($contents, $offset, 4))['length'];
            $chunkType = substr($contents, $offset + 4, 4);

            if ($chunkType === 'acTL') {
                return true;
            }

            if ($chunkType === 'IEND' || $chunkLength > $length - $offset - 12) {
                return false;
            }

            $offset += 12 + $chunkLength;
        }

        return false;
    }

    private function webpHasAnimationChunk(string $contents): bool
    {
        if (substr($contents, 0, 4) !== 'RIFF' || substr($contents, 8, 4) !== 'WEBP') {
            return false;
        }

        $riffLength = unpack('Vlength', substr($contents, 4, 4))['length'] + 8;
        $length = min(strlen($contents), $riffLength);
        for ($offset = 12; $offset + 8 <= $length;) {
            $chunkType = substr($contents, $offset, 4);
            $chunkLength = unpack('Vlength', substr($contents, $offset + 4, 4))['length'];
            $payloadOffset = $offset + 8;

            if ($chunkType === 'ANIM' || $chunkType === 'ANMF') {
                return true;
            }

            if ($chunkType === 'VP8X' && $chunkLength > 0 && isset($contents[$payloadOffset])) {
                if ((ord($contents[$payloadOffset]) & 0b00000010) !== 0) {
                    return true;
                }
            }

            if ($chunkLength > $length - $payloadOffset) {
                return false;
            }

            $offset = $payloadOffset + $chunkLength + ($chunkLength % 2);
        }

        return false;
    }

    private function applyExifOrientation(\GdImage $image, string $path): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $orientation = @exif_read_data($path, 'IFD0')['Orientation'] ?? 1;
        $flip = match ($orientation) {
            2, 5, 7 => IMG_FLIP_HORIZONTAL,
            4 => IMG_FLIP_VERTICAL,
            default => null,
        };

        if ($flip !== null) {
            imageflip($image, $flip);
        }

        $rotation = match ($orientation) {
            3 => 180,
            5, 8 => 90,
            6, 7 => -90,
            default => null,
        };

        if ($rotation === null) {
            return $image;
        }

        $rotated = imagerotate($image, $rotation, 0);
        if ($rotated === false) {
            return $image;
        }

        imagedestroy($image);

        return $rotated;
    }

    /**
     * @return array{contents: string, mime_type: string, width: int, height: int}
     */
    private function encodeDerivative(\GdImage $source, int $maxWidth, int $maxHeight): array
    {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $scale = min($maxWidth / $sourceWidth, $maxHeight / $sourceHeight, 1);
        $width = max(1, (int) round($sourceWidth * $scale));
        $height = max(1, (int) round($sourceHeight * $scale));
        $derivative = imagecreatetruecolor($width, $height);

        imagealphablending($derivative, false);
        imagesavealpha($derivative, true);
        $transparent = imagecolorallocatealpha($derivative, 0, 0, 0, 127);
        imagefill($derivative, 0, 0, $transparent);
        imagecopyresampled($derivative, $source, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight);

        ob_start();
        $encoded = imagewebp($derivative, null, 85);
        $contents = ob_get_clean();
        imagedestroy($derivative);

        if (! $encoded || $contents === false || $contents === '') {
            throw ValidationException::withMessages(['image' => 'The image could not be converted to WebP.']);
        }

        return [
            'contents' => $contents,
            'mime_type' => 'image/webp',
            'width' => $width,
            'height' => $height,
        ];
    }
}
