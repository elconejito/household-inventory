<?php

namespace App\Actions;

use App\Actions\Concerns\RequiresActiveHouseholdMembership;
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
    use RequiresActiveHouseholdMembership;

    public function __construct(private readonly ProcessItemImage $processor) {}

    public function upload(Household $household, Item $item, User $uploader, UploadedFile $file, ?string $caption = null): ItemImage
    {
        $disk = (string) config('inventory.images_disk', 'inventory-images');
        $this->assertPrivateStorageDisk($disk);
        $derivatives = $this->processor->process($file);
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
                $this->assertActiveMembership($lockedHousehold, $uploader);
                $lockedItem = $lockedHousehold->items()->lockForUpdate()->findOrFail($item->getKey());
                $isPrimary = ! ItemImage::query()->where('item_id', $lockedItem->getKey())->exists();
                $storage = Storage::disk($disk);

                foreach ([
                    $thumbnailPath => $derivatives['thumbnail']['contents'],
                    $displayPath => $derivatives['display']['contents'],
                ] as $path => $contents) {
                    $storedPaths[] = $path;
                    if (! $storage->put($path, $contents, ['visibility' => 'private'])) {
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

    private function assertPrivateStorageDisk(string $disk): void
    {
        $diskConfig = config('filesystems.disks.'.$disk);
        $visibility = is_array($diskConfig) ? strtolower((string) ($diskConfig['visibility'] ?? '')) : '';
        $directoryVisibility = is_array($diskConfig) ? strtolower((string) ($diskConfig['directory_visibility'] ?? '')) : '';

        if (! is_array($diskConfig)
            || $disk === 'public'
            || $visibility === 'public'
            || $directoryVisibility === 'public') {
            throw new RuntimeException('The configured image storage disk is not private.');
        }

        if (($diskConfig['driver'] ?? null) !== 'local') {
            if ($visibility !== 'private') {
                throw new RuntimeException('The configured image storage disk is not private.');
            }

            return;
        }

        $root = $diskConfig['root'] ?? null;
        if (! is_string($root) || $root === '' || ! str_starts_with($root, DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('The configured image storage disk has an unsafe root.');
        }

        $exposedRoots = [public_path(), storage_path('app/public')];
        $links = config('filesystems.links', []);
        if (! is_array($links)) {
            throw new RuntimeException('The configured image storage links are invalid.');
        }

        foreach ($links as $target) {
            if (! is_string($target) || $this->canonicalPath($target) === null) {
                throw new RuntimeException('The configured image storage links are invalid.');
            }

            $exposedRoots[] = $target;
        }

        foreach ($exposedRoots as $exposedRoot) {
            if ($this->isPathWithin($root, $exposedRoot)) {
                throw new RuntimeException('The configured image storage disk is exposed by a public path.');
            }
        }
    }

    private function isPathWithin(string $path, string $parent): bool
    {
        $canonicalPath = $this->canonicalPath($path);
        $canonicalParent = $this->canonicalPath($parent);

        if ($canonicalPath === null || $canonicalParent === null) {
            return false;
        }

        return $canonicalPath === $canonicalParent
            || str_starts_with($canonicalPath, rtrim($canonicalParent, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }

    private function canonicalPath(string $path): ?string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        if (! str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return null;
        }

        $segments = [];
        foreach (explode(DIRECTORY_SEPARATOR, $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        $normalizedPath = DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $segments);
        $existingPath = $normalizedPath;
        $remainingSegments = [];

        while (! file_exists($existingPath) && ! is_link($existingPath)) {
            $parent = dirname($existingPath);
            if ($parent === $existingPath) {
                return null;
            }

            array_unshift($remainingSegments, basename($existingPath));
            $existingPath = $parent;
        }

        $realExistingPath = realpath($existingPath);
        if ($realExistingPath === false) {
            return null;
        }

        $canonicalExistingPath = rtrim($realExistingPath, DIRECTORY_SEPARATOR);
        $canonicalExistingPath = $canonicalExistingPath === '' ? DIRECTORY_SEPARATOR : $canonicalExistingPath;

        return $canonicalExistingPath
            .($remainingSegments === [] ? '' : DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $remainingSegments));
    }
}
