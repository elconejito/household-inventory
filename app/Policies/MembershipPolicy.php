<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Models\Membership;
use App\Models\User;

class MembershipPolicy
{
    public function manage(User $user, Membership $membership): bool
    {
        return $user->memberships()
            ->where('household_id', $membership->household_id)
            ->where('role', MembershipRole::Owner->value)
            ->exists();
    }

    public function leave(User $user, Membership $membership): bool
    {
        return $membership->user_id === $user->getKey()
            && $user->memberships()->whereKey($membership->getKey())->exists();
    }
}
