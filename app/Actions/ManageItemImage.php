<?php

namespace App\Actions;

use App\Actions\Concerns\RequiresActiveHouseholdMembership;
use App\Models\Household;
use App\Models\Item;
use App\Models\ItemImage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ManageItemImage
{
    use RequiresActiveHouseholdMembership;

    /** @param array{caption?: string|null, is_primary?: bool} $data */
    public function update(Household $household, ItemImage $itemImage, array $data, User $actor): ItemImage
    {
        return DB::transaction(function () use ($household, $itemImage, $data, $actor): ItemImage {
            [$lockedItem, $lockedImage] = $this->lockItemAndImage($household, $itemImage, $actor);

            if (($data['is_primary'] ?? false) === true) {
                ItemImage::query()
                    ->where('item_id', $lockedItem->getKey())
                    ->whereKeyNot($lockedImage->getKey())
                    ->update(['is_primary' => false]);
                $data['is_primary'] = true;
            }

            $lockedImage->fill($data)->save();

            return $lockedImage->refresh();
        });
    }

    public function delete(Household $household, ItemImage $itemImage, User $actor): void
    {
        DB::transaction(function () use ($household, $itemImage, $actor): void {
            [$lockedItem, $lockedImage] = $this->lockItemAndImage($household, $itemImage, $actor);
            $wasPrimary = $lockedImage->is_primary;
            $lockedImage->delete();

            if ($wasPrimary) {
                $this->promoteOldest($lockedItem);
            }
        });
    }

    public function restore(Household $household, ItemImage $itemImage, User $actor): ItemImage
    {
        return DB::transaction(function () use ($household, $itemImage, $actor): ItemImage {
            [$lockedItem, $lockedImage] = $this->lockItemAndImage($household, $itemImage, $actor, withTrashedImage: true);
            if (! $lockedImage->trashed()) {
                abort(409, 'The photo is already active.');
            }

            $hasPrimary = ItemImage::query()->where('item_id', $lockedItem->getKey())->where('is_primary', true)->exists();
            $lockedImage->is_primary = ! $hasPrimary;
            $lockedImage->restore();

            return $lockedImage->refresh();
        });
    }

    /** @return array{Item, ItemImage} */
    private function lockItemAndImage(Household $household, ItemImage $itemImage, User $actor, bool $withTrashedImage = false): array
    {
        $lockedHousehold = Household::query()->lockForUpdate()->findOrFail($household->getKey());
        $this->assertActiveMembership($lockedHousehold, $actor);
        $lockedItem = $lockedHousehold->items()->lockForUpdate()->findOrFail($itemImage->item_id);
        $imageQuery = ItemImage::query()->where('item_id', $lockedItem->getKey())->lockForUpdate();

        if ($withTrashedImage) {
            $imageQuery->withTrashed();
        }

        $lockedImage = $imageQuery->findOrFail($itemImage->getKey());

        return [$lockedItem, $lockedImage];
    }

    private function promoteOldest(Item $item): void
    {
        $oldest = ItemImage::query()
            ->where('item_id', $item->getKey())
            ->orderBy('uploaded_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if ($oldest !== null) {
            $oldest->is_primary = true;
            $oldest->save();
        }
    }
}
