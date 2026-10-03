<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Models\Household;
use App\Models\User;

class HouseholdPolicy
{
    public function manage(User $user, Household $household): bool
    {
        return $user->memberships()
            ->where('household_id', $household->getKey())
            ->where('role', MembershipRole::Owner->value)
            ->exists();
    }
}
