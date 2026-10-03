<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Models\Category;
use App\Models\User;

class CategoryPolicy
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
    public function view(User $user, Category $category): bool
    {
        return $this->belongsToHousehold($user, $category);
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
    public function update(User $user, Category $category): bool
    {
        return $this->belongsToHousehold($user, $category);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Category $category): bool
    {
        return $this->belongsToHousehold($user, $category);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Category $category): bool
    {
        return $this->belongsToHousehold($user, $category);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Category $category): bool
    {
        return $user->memberships()
            ->where('household_id', $category->household_id)
            ->where('role', MembershipRole::Owner->value)
            ->exists();
    }

    private function belongsToHousehold(User $user, Category $category): bool
    {
        return $user->households()->whereKey($category->household_id)->exists();
    }
}
