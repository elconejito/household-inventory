<?php

namespace App\Actions;

use App\Enums\MembershipRole;
use App\Exceptions\HouseholdAdministrationConflict;
use App\Models\Household;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ManageMembership
{
    public function update(Household $household, Membership $membership, MembershipRole $role, User $actor): Membership
    {
        return DB::transaction(function () use ($household, $membership, $role, $actor): Membership {
            $lockedHousehold = Household::query()->lockForUpdate()->findOrFail($household->getKey());
            $this->assertOwner($lockedHousehold, $actor);
            $lockedMembership = $lockedHousehold->memberships()->lockForUpdate()->findOrFail($membership->getKey());

            if ($lockedMembership->role === MembershipRole::Owner && $role !== MembershipRole::Owner) {
                $this->ensureAnotherOwnerRemains($lockedHousehold, $lockedMembership);
            }

            $lockedMembership->update(['role' => $role]);

            return $lockedMembership->fresh();
        });
    }

    public function remove(Household $household, Membership $membership, User $actor): void
    {
        DB::transaction(function () use ($household, $membership, $actor): void {
            $lockedHousehold = Household::query()->lockForUpdate()->findOrFail($household->getKey());
            $this->assertOwner($lockedHousehold, $actor);
            $lockedMembership = $lockedHousehold->memberships()->lockForUpdate()->findOrFail($membership->getKey());

            if ($lockedMembership->role === MembershipRole::Owner) {
                $this->ensureAnotherOwnerRemains($lockedHousehold, $lockedMembership);
            }

            $lockedMembership->delete();
        });
    }

    public function leave(Household $household, Membership $membership): void
    {
        DB::transaction(function () use ($household, $membership): void {
            $lockedHousehold = Household::query()->lockForUpdate()->findOrFail($household->getKey());
            $lockedMembership = $lockedHousehold->memberships()->lockForUpdate()->findOrFail($membership->getKey());

            if ($lockedMembership->role === MembershipRole::Owner) {
                $this->ensureAnotherOwnerRemains($lockedHousehold, $lockedMembership);
            }

            $lockedMembership->delete();
        });
    }

    public function restore(Household $household, Membership $membership, User $actor): Membership
    {
        return DB::transaction(function () use ($household, $membership, $actor): Membership {
            $lockedHousehold = Household::query()->lockForUpdate()->findOrFail($household->getKey());
            $this->assertOwner($lockedHousehold, $actor);
            $lockedMembership = $lockedHousehold->memberships()->withTrashed()->lockForUpdate()->findOrFail($membership->getKey());

            if (! $lockedMembership->trashed()) {
                throw new HouseholdAdministrationConflict('membership_not_removed', 'This membership is already active.');
            }

            $lockedMembership->restore();

            return $lockedMembership->fresh();
        });
    }

    private function ensureAnotherOwnerRemains(Household $household, Membership $membership): void
    {
        $otherOwnersRemain = $household->memberships()
            ->where('id', '!=', $membership->getKey())
            ->where('role', MembershipRole::Owner->value)
            ->exists();

        if (! $otherOwnersRemain) {
            throw new HouseholdAdministrationConflict('final_owner_required', 'A household must keep at least one active owner.');
        }
    }

    private function assertOwner(Household $household, User $actor): void
    {
        $isOwner = $household->memberships()
            ->where('user_id', $actor->getKey())
            ->where('role', MembershipRole::Owner->value)
            ->exists();

        if (! $isOwner) {
            abort(403);
        }
    }
}
