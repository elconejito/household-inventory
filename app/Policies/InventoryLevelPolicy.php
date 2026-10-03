<?php

namespace App\Policies;

use App\Models\InventoryLevel;
use App\Models\User;

class InventoryLevelPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->households()->exists();
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, InventoryLevel $inventoryLevel): bool
    {
        return $user->households()->whereHas('items', fn ($query) => $query->whereKey($inventoryLevel->item_id))->exists();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->households()->exists();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, InventoryLevel $inventoryLevel): bool
    {
        return $user->households()->whereHas('items', fn ($query) => $query->whereKey($inventoryLevel->item_id))->exists();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, InventoryLevel $inventoryLevel): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, InventoryLevel $inventoryLevel): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, InventoryLevel $inventoryLevel): bool
    {
        return false;
    }
}
