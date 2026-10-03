<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Models\Item;
use App\Models\ItemImage;
use App\Models\User;

class ItemImagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->households()->exists();
    }

    public function view(User $user, ItemImage $itemImage): bool
    {
        return $this->belongsToHousehold($user, $itemImage);
    }

    public function create(User $user, Item $item): bool
    {
        return ! $item->trashed() && $user->households()->whereKey($item->household_id)->exists();
    }

    public function update(User $user, ItemImage $itemImage): bool
    {
        return $this->belongsToHousehold($user, $itemImage);
    }

    public function delete(User $user, ItemImage $itemImage): bool
    {
        return $this->belongsToHousehold($user, $itemImage);
    }

    public function restore(User $user, ItemImage $itemImage): bool
    {
        return $this->belongsToHousehold($user, $itemImage);
    }

    public function forceDelete(User $user, ItemImage $itemImage): bool
    {
        $item = $itemImage->item;

        return $item !== null
            && $user->memberships()
                ->where('household_id', $item->household_id)
                ->where('role', MembershipRole::Owner->value)
                ->exists();
    }

    private function belongsToHousehold(User $user, ItemImage $itemImage): bool
    {
        $item = $itemImage->item;

        return $item !== null
            && ! $item->trashed()
            && $user->households()->whereKey($item->household_id)->exists();
    }
}
