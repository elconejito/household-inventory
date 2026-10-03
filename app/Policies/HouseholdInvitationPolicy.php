<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Models\HouseholdInvitation;
use App\Models\User;

class HouseholdInvitationPolicy
{
    public function manage(User $user, HouseholdInvitation $invitation): bool
    {
        return $user->memberships()
            ->where('household_id', $invitation->household_id)
            ->where('role', MembershipRole::Owner->value)
            ->exists();
    }
}
