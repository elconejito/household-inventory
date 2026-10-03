<?php

namespace App\Actions\Concerns;

use App\Models\Household;
use App\Models\Membership;
use App\Models\User;

trait RequiresActiveHouseholdMembership
{
    private function assertActiveMembership(Household $household, User $actor): void
    {
        $isActiveMember = Membership::query()
            ->where('household_id', $household->getKey())
            ->where('user_id', $actor->getKey())
            ->lockForUpdate()
            ->exists();

        abort_unless($isActiveMember, 403);
    }
}
