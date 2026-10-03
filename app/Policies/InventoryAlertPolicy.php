<?php

namespace App\Policies;

use App\Models\InventoryAlert;
use App\Models\User;

class InventoryAlertPolicy
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
    public function view(User $user, InventoryAlert $inventoryAlert): bool
    {
        return $user->households()->whereKey($inventoryAlert->household_id)->exists();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->households()->exists();
    }

    /**
     * Determine whether the user can resolve the model.
     */
    public function resolve(User $user, InventoryAlert $inventoryAlert): bool
    {
        return $user->households()->whereKey($inventoryAlert->household_id)->exists();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, InventoryAlert $inventoryAlert): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, InventoryAlert $inventoryAlert): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, InventoryAlert $inventoryAlert): bool
    {
        return false;
    }
}
