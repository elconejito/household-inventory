<?php

namespace App\Actions;

use App\Models\Household;
use App\Models\Item;
use App\Models\ItemImage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class UploadItemImage
{
    public function __construct(private readonly ProcessItemImage $processor) {}

    public function upload(Household $household, Item $item, User $uploader, UploadedFile $file, ?string $caption = null): ItemImage
    {
        $derivatives = $this->processor->process($file);
        $disk = (string) config('inventory.images_disk', 'inventory-images');
        $directory = 'items/'.$item->getKey().'/'.Str::uuid();
        $thumbnailPath = $directory.'/thumbnail.webp';
        $displayPath = $directory.'/display.webp';
        $storedPaths = [];

        try {
            return DB::transaction(function () use (
                $household,
                $item,
                $uploader,
                $caption,
                $derivatives,
                $disk,
                $thumbnailPath,
                $displayPath,
                &$storedPaths,
            ): ItemImage {
                $lockedHousehold = Household::query()->lockForUpdate()->findOrFail($household->getKey());
                $lockedItem = $lockedHousehold->items()->lockForUpdate()->findOrFail($item->getKey());
                $isPrimary = ! ItemImage::query()->where('item_id', $lockedItem->getKey())->exists();
                $storage = Storage::disk($disk);

                foreach ([
                    $thumbnailPath => $derivatives['thumbnail']['contents'],
                    $displayPath => $derivatives['display']['contents'],
                ] as $path => $contents) {
                    $storedPaths[] = $path;
                    if (! $storage->put($path, $contents)) {
                        throw new RuntimeException('The private image storage disk rejected an image derivative.');
                    }
                }

                return ItemImage::query()->create([
                    'item_id' => $lockedItem->getKey(),
                    'disk' => $disk,
                    'thumbnail_path' => $thumbnailPath,
                    'thumbnail_mime_type' => $derivatives['thumbnail']['mime_type'],
                    'thumbnail_width' => $derivatives['thumbnail']['width'],
                    'thumbnail_height' => $derivatives['thumbnail']['height'],
                    'display_path' => $displayPath,
                    'display_mime_type' => $derivatives['display']['mime_type'],
                    'display_width' => $derivatives['display']['width'],
                    'display_height' => $derivatives['display']['height'],
                    'caption' => $caption,
                    'is_primary' => $isPrimary,
                    'uploaded_by' => $uploader->getKey(),
                    'uploaded_at' => now(),
                ]);
            });
        } catch (Throwable $exception) {
            foreach ($storedPaths as $path) {
                try {
                    Storage::disk($disk)->delete($path);
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }

            throw $exception;
        }
    }
}
