<?php

namespace Tests\Feature\Policies;

use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\Membership;
use App\Models\User;
use App\Policies\HouseholdInvitationPolicy;
use App\Policies\HouseholdPolicy;
use App\Policies\MembershipPolicy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class HouseholdAdministrationPolicyTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_only_active_owners_can_manage_household_settings_or_invitations(): void
    {
        $household = Household::factory()->create();
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $outsider = User::factory()->create();
        $ownerMembership = Membership::factory()->for($household)->for($owner)->owner()->create();
        Membership::factory()->for($household)->for($member)->create();
        $invitation = HouseholdInvitation::factory()->for($household)->for($owner, 'inviter')->create();

        $householdPolicy = new HouseholdPolicy;
        $invitationPolicy = new HouseholdInvitationPolicy;
        $this->assertTrue($householdPolicy->manage($owner, $household));
        $this->assertFalse($householdPolicy->manage($member, $household));
        $this->assertFalse($householdPolicy->manage($outsider, $household));
        $this->assertTrue($invitationPolicy->manage($owner, $invitation));
        $this->assertFalse($invitationPolicy->manage($member, $invitation));
        $this->assertFalse($invitationPolicy->manage($outsider, $invitation));

        $ownerMembership->delete();
        $this->assertFalse($householdPolicy->manage($owner, $household));
        $this->assertFalse($invitationPolicy->manage($owner, $invitation));
    }

    public function test_only_owners_can_manage_memberships_and_users_can_leave_only_their_active_membership(): void
    {
        $household = Household::factory()->create();
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $outsider = User::factory()->create();
        Membership::factory()->for($household)->for($owner)->owner()->create();
        $membership = Membership::factory()->for($household)->for($member)->create();
        $policy = new MembershipPolicy;

        $this->assertTrue($policy->manage($owner, $membership));
        $this->assertFalse($policy->manage($member, $membership));
        $this->assertFalse($policy->manage($outsider, $membership));
        $this->assertTrue($policy->leave($member, $membership));
        $this->assertFalse($policy->leave($owner, $membership));
        $this->assertFalse($policy->leave($outsider, $membership));

        $membership->delete();
        $this->assertFalse($policy->leave($member, $membership));
    }

    public function test_membership_management_does_not_cross_household_boundaries(): void
    {
        $household = Household::factory()->create();
        $foreignHousehold = Household::factory()->create();
        $owner = User::factory()->create();
        $foreignMember = User::factory()->create();
        Membership::factory()->for($household)->for($owner)->owner()->create();
        $membership = Membership::factory()->for($foreignHousehold)->for($foreignMember)->create();

        $this->assertFalse((new MembershipPolicy)->manage($owner, $membership));
    }
}
