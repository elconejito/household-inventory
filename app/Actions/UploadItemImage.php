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
        if (! is_string($root) || $root === '') {
            throw new RuntimeException('The configured image storage disk has an unsafe root.');
        }

        $canonicalRoot = $this->canonicalPath($root);
        if ($canonicalRoot === null) {
            throw new RuntimeException('The configured image storage disk has an unsafe root.');
        }

        $exposedRoots = [public_path(), storage_path('app/public')];
        $links = config('filesystems.links', []);
        if (! is_array($links)) {
            throw new RuntimeException('The configured image storage links are invalid.');
        }

        foreach ($links as $target) {
            $canonicalTarget = is_string($target) ? $this->canonicalPath($target) : null;
            if ($canonicalTarget === null) {
                throw new RuntimeException('The configured image storage links are invalid.');
            }

            $exposedRoots[] = $canonicalTarget;
        }

        foreach ($exposedRoots as $exposedRoot) {
            $canonicalExposedRoot = $this->canonicalPath($exposedRoot);
            if ($canonicalExposedRoot === null) {
                throw new RuntimeException('The configured image storage paths cannot be verified.');
            }

            if ($this->isPathWithin($canonicalRoot, $canonicalExposedRoot)) {
                throw new RuntimeException('The configured image storage disk is exposed by a public path.');
            }
        }
    }

    private function isPathWithin(string $path, string $parent): bool
    {
        $isWindowsPath = $this->isWindowsPath($path);
        if ($isWindowsPath !== $this->isWindowsPath($parent)) {
            return false;
        }

        $path = rtrim($path, '/');
        $parent = rtrim($parent, '/');
        $path = $path === '' ? '/' : $path;
        $parent = $parent === '' ? '/' : $parent;
        $pathPrefix = $parent === '/' ? '/' : $parent.'/';

        return ($isWindowsPath ? strcasecmp($path, $parent) === 0 : $path === $parent)
            || ($isWindowsPath
                ? strncasecmp($path, $pathPrefix, strlen($pathPrefix)) === 0
                : str_starts_with($path, $pathPrefix));
    }

    private function canonicalPath(string $path): ?string
    {
        $path = str_replace('\\', '/', $path);
        $anchor = $this->pathAnchor($path);
        if ($anchor === null) {
            return null;
        }

        $resolvedPath = $anchor;
        $unresolvedSegments = [];
        $pathAfterAnchor = substr($path, strlen($anchor));
        foreach (explode('/', $pathAfterAnchor) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($unresolvedSegments !== []) {
                    return null;
                }

                $resolvedPath = $this->parentPath($resolvedPath);

                continue;
            }

            if ($unresolvedSegments === []) {
                $candidatePath = rtrim($resolvedPath, '/').'/'.$segment;
                if (file_exists($candidatePath) || is_link($candidatePath)) {
                    $realCandidatePath = realpath($candidatePath);
                    if ($realCandidatePath === false) {
                        return null;
                    }

                    $resolvedPath = str_replace('\\', '/', $realCandidatePath);

                    continue;
                }
            }

            $unresolvedSegments[] = $segment;
        }

        $canonicalResolvedPath = rtrim($resolvedPath, '/');
        $canonicalResolvedPath = $canonicalResolvedPath === '' ? '/' : $canonicalResolvedPath;
        if (preg_match('/^[A-Za-z]:$/', $canonicalResolvedPath) === 1) {
            $canonicalResolvedPath .= '/';
        }

        return $canonicalResolvedPath
            .($unresolvedSegments === [] ? '' : '/'.implode('/', $unresolvedSegments));
    }

    private function pathAnchor(string $path): ?string
    {
        if (preg_match('/^[A-Za-z]:\//', $path) === 1) {
            return strtoupper($path[0]).':/';
        }

        if (preg_match('#^//([^/]+)/([^/]+)(?:/|$)#', $path, $matches) === 1) {
            return '//'.$matches[1].'/'.$matches[2].'/';
        }

        if (str_starts_with($path, '/')) {
            return '/';
        }

        return null;
    }

    private function parentPath(string $path): string
    {
        $anchor = $this->pathAnchor($path);
        if ($anchor === null) {
            return $path;
        }

        if (strcasecmp(rtrim($path, '/'), rtrim($anchor, '/')) === 0) {
            return $anchor;
        }

        $segments = explode('/', trim(substr($path, strlen($anchor)), '/'));
        array_pop($segments);

        return $anchor.implode('/', $segments);
    }

    private function isWindowsPath(string $path): bool
    {
        return preg_match('/^[A-Za-z]:\//', $path) === 1 || str_starts_with($path, '//');
    }
}
