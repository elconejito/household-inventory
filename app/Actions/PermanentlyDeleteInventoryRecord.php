<?php

namespace App\Actions;

use App\Exceptions\PermanentDeletionBlocked;
use App\Models\Category;
use App\Models\Household;
use App\Models\InventoryMovementEntry;
use App\Models\Item;
use App\Models\ItemImage;
use App\Models\Location;
use App\Models\Note;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class PermanentlyDeleteInventoryRecord
{
    public function permanentlyDeleteItem(Household $household, User $user, Item $item): void
    {
        $this->runWithFileRollback(function (array &$backups, array &$attempted) use ($household, $user, $item): void {
            $lockedHousehold = $this->lockHousehold($household);
            $lockedItem = $lockedHousehold->items()->withTrashed()->lockForUpdate()->findOrFail($item->getKey());

            if (! $lockedItem->trashed()) {
                abort(409, 'The item must be archived before it can be permanently deleted.');
            }

            Gate::forUser($user)->authorize('forceDelete', $lockedItem);
            $images = ItemImage::query()->withTrashed()->where('item_id', $lockedItem->getKey())->lockForUpdate()->get();

            $this->deleteImageFiles($images, $backups, $attempted);
            $lockedItem->forceDelete();
        });
    }

    public function permanentlyDeleteCategory(Household $household, User $user, Category $category): void
    {
        DB::transaction(function () use ($household, $user, $category): void {
            $lockedHousehold = $this->lockHousehold($household);
            $lockedCategory = $lockedHousehold->categories()->withTrashed()->lockForUpdate()->findOrFail($category->getKey());

            if (! $lockedCategory->trashed()) {
                abort(409, 'The category must be archived before it can be permanently deleted.');
            }

            Gate::forUser($user)->authorize('forceDelete', $lockedCategory);
            $lockedCategory->items()->detach();
            $lockedCategory->forceDelete();
        });
    }

    public function permanentlyDeleteLocation(Household $household, User $user, Location $location): void
    {
        DB::transaction(function () use ($household, $user, $location): void {
            $lockedHousehold = $this->lockHousehold($household);
            $lockedLocation = $lockedHousehold->locations()->withTrashed()->lockForUpdate()->findOrFail($location->getKey());

            if (! $lockedLocation->trashed()) {
                abort(409, 'The location must be archived before it can be permanently deleted.');
            }

            Gate::forUser($user)->authorize('forceDelete', $lockedLocation);
            $blockers = [];

            if ($lockedLocation->children()->withTrashed()->exists()) {
                $blockers[] = 'child locations';
            }

            if ($lockedLocation->inventoryLevels()->exists()) {
                $blockers[] = 'inventory levels';
            }

            if (InventoryMovementEntry::query()->where('location_id', $lockedLocation->getKey())->exists()) {
                $blockers[] = 'movement entries';
            }

            if ($blockers !== []) {
                throw new PermanentDeletionBlocked('location', $blockers);
            }

            $lockedLocation->forceDelete();
        });
    }

    public function permanentlyDeleteNote(Household $household, User $user, Note $note): void
    {
        DB::transaction(function () use ($household, $user, $note): void {
            $lockedHousehold = $this->lockHousehold($household);
            $lockedNote = Note::query()->withTrashed()->lockForUpdate()->findOrFail($note->getKey());

            if (! $lockedNote->trashed()) {
                abort(409, 'The note must be archived before it can be permanently deleted.');
            }

            $lockedNotable = $this->lockNotable($lockedHousehold, $lockedNote);
            $lockedNote->setRelation('notable', $lockedNotable);
            Gate::forUser($user)->authorize('forceDelete', $lockedNote);
            $lockedNote->forceDelete();
        });
    }

    public function permanentlyDeleteImage(Household $household, User $user, ItemImage $itemImage): void
    {
        $this->runWithFileRollback(function (array &$backups, array &$attempted) use ($household, $user, $itemImage): void {
            $lockedHousehold = $this->lockHousehold($household);
            $lockedItem = $lockedHousehold->items()->withTrashed()->lockForUpdate()->findOrFail($itemImage->item_id);
            $lockedImage = ItemImage::query()->withTrashed()->where('item_id', $lockedItem->getKey())
                ->lockForUpdate()->findOrFail($itemImage->getKey());

            if (! $lockedImage->trashed()) {
                abort(409, 'The image must be archived before it can be permanently deleted.');
            }

            Gate::forUser($user)->authorize('forceDelete', $lockedImage);
            $this->deleteImageFiles(collect([$lockedImage]), $backups, $attempted);
            $lockedImage->forceDelete();
        });
    }

    private function lockNotable(Household $household, Note $note): Model
    {
        $class = Relation::getMorphedModel($note->notable_type);
        abort_unless($class !== null && is_subclass_of($class, Model::class), 404);
        $query = $class::query()->where('household_id', $household->getKey())->lockForUpdate();

        if (method_exists($class, 'trashed')) {
            $query->withTrashed();
        }

        return $query->findOrFail($note->notable_id);
    }

    private function lockHousehold(Household $household): Household
    {
        return Household::query()->lockForUpdate()->findOrFail($household->getKey());
    }

    /** @param iterable<ItemImage> $images */
    private function runWithFileRollback(callable $operation): void
    {
        /** @var array<string, array{disk: string, path: string, backup: resource}> $backups */
        $backups = [];
        /** @var array<string, true> $attempted */
        $attempted = [];

        try {
            DB::transaction(function () use ($operation, &$backups, &$attempted): void {
                $operation($backups, $attempted);
            });
        } catch (Throwable $exception) {
            $restorationFailure = null;
            foreach ($attempted as $key => $_) {
                $file = $backups[$key];
                try {
                    rewind($file['backup']);
                    if (! Storage::disk($file['disk'])->put($file['path'], $file['backup'])) {
                        throw new RuntimeException('The image derivative could not be restored to storage.');
                    }
                } catch (Throwable $restoreException) {
                    $restorationFailure ??= $restoreException;
                }
            }

            if ($restorationFailure !== null) {
                report($restorationFailure);
            }

            throw $exception;
        } finally {
            foreach ($backups as $file) {
                fclose($file['backup']);
            }
        }
    }

    /** @param iterable<ItemImage> $images
     * @param  array<string, array{disk: string, path: string, backup: resource}>  $backups
     * @param  array<string, true>  $attempted
     */
    private function deleteImageFiles(iterable $images, array &$backups, array &$attempted): void
    {
        foreach ($images as $image) {
            foreach ([$image->thumbnail_path, $image->display_path] as $path) {
                $key = $image->disk.'|'.$path;
                if (isset($backups[$key])) {
                    continue;
                }

                $storage = Storage::disk($image->disk);
                if (! $storage->exists($path)) {
                    continue;
                }

                $source = $storage->readStream($path);
                $backup = fopen('php://temp/maxmemory:2097152', 'w+b');
                if (! is_resource($source) || ! is_resource($backup)) {
                    if (is_resource($source)) {
                        fclose($source);
                    }
                    if (is_resource($backup)) {
                        fclose($backup);
                    }
                    throw new RuntimeException('The image derivative could not be backed up before deletion.');
                }

                try {
                    if (stream_copy_to_stream($source, $backup) === false) {
                        fclose($backup);
                        throw new RuntimeException('The image derivative could not be backed up before deletion.');
                    }
                } finally {
                    fclose($source);
                }

                rewind($backup);
                $backups[$key] = ['disk' => $image->disk, 'path' => $path, 'backup' => $backup];
            }
        }

        foreach ($backups as $key => $file) {
            $attempted[$key] = true;
            if (! Storage::disk($file['disk'])->delete($file['path'])) {
                throw new RuntimeException('The image derivative could not be deleted from storage.');
            }
        }
    }
}
