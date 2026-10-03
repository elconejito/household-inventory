<?php

namespace Tests\Feature\Api;

use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class HouseholdAdministrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_owner_can_update_household_settings_and_members_cannot(): void
    {
        [$owner, $household] = $this->householdOwner();
        [$member] = $this->householdMember($household);

        $this->signInAs($owner)->patchJson('/api/household', [
            'data' => ['name' => "  Ramos\t Home  "],
        ])->assertOk()->assertJsonPath('data.name', 'Ramos Home');

        $this->signInAs($member)->patchJson('/api/household', [
            'data' => ['name' => 'Changed'],
        ])->assertForbidden();
        $this->getJson('/api/memberships')->assertForbidden();
        $this->getJson('/api/household-invitations')->assertForbidden();

        $this->assertSame('Ramos Home', $household->fresh()->name);
    }

    public function test_membership_index_is_scoped_paginated_and_includes_user_only_when_requested(): void
    {
        [$owner, $household] = $this->householdOwner();
        [$member, $membership] = $this->householdMember($household);
        $otherHousehold = Household::factory()->create();
        $foreignUser = User::factory()->create();
        Membership::factory()->for($otherHousehold)->for($foreignUser)->create();

        $this->signInAs($owner)->getJson('/api/memberships?include=user')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.user.id', (string) $member->id)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonMissing(['email' => $foreignUser->email]);

        $membership->delete();
        $this->getJson('/api/memberships?filter[trashed]=only&include=user')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.deleted_at', $membership->fresh()->deleted_at->toISOString());

        $this->getJson('/api/memberships?per_page=20')->assertUnprocessable();
        $this->getJson('/api/memberships?include=household')->assertBadRequest();
    }

    public function test_owner_can_remove_restore_and_change_membership_role(): void
    {
        [$owner, $household] = $this->householdOwner();
        [$member, $membership] = $this->householdMember($household);
        $this->signInAs($owner);

        $this->patchJson('/api/memberships/'.$membership->id, ['data' => ['role' => 'owner']])
            ->assertOk()->assertJsonPath('data.role', 'owner');
        $this->patchJson('/api/memberships/'.$membership->id, ['data' => ['role' => 'member', 'user_id' => 999]])
            ->assertUnprocessable();
        $this->assertDatabaseHas('memberships', ['id' => $membership->id, 'role' => 'owner']);
        $this->deleteJson('/api/memberships/'.$membership->id)->assertNoContent();
        $this->assertSoftDeleted('memberships', ['id' => $membership->id]);

        $this->postJson('/api/memberships/'.$membership->id.'/restore')->assertOk()
            ->assertJsonPath('data.role', 'owner')
            ->assertJsonPath('data.deleted_at', null);
        $this->assertDatabaseHas('memberships', ['id' => $membership->id, 'deleted_at' => null]);
    }

    public function test_last_owner_cannot_be_removed_demoted_or_leave(): void
    {
        [$owner, , $membership] = $this->householdOwner();
        $this->signInAs($owner);

        $this->deleteJson('/api/memberships/'.$membership->id)->assertConflict()
            ->assertJsonPath('errors.0.code', 'final_owner_required');
        $this->patchJson('/api/memberships/'.$membership->id, ['data' => ['role' => 'member']])->assertConflict()
            ->assertJsonPath('errors.0.code', 'final_owner_required');
        $this->postJson('/api/membership/leave')->assertConflict()
            ->assertJsonPath('errors.0.code', 'final_owner_required');

        $this->assertDatabaseHas('memberships', ['id' => $membership->id, 'deleted_at' => null, 'role' => 'owner']);
    }

    public function test_nonfinal_owner_can_leave_while_another_owner_remains(): void
    {
        [, $household, $ownerMembership] = $this->householdOwner();
        [$secondOwner, $secondOwnerMembership] = $this->householdMember($household);
        $secondOwnerMembership->update(['role' => 'owner']);
        $this->signInAs($secondOwner)->postJson('/api/membership/leave')->assertNoContent();
        $this->assertSoftDeleted('memberships', ['id' => $secondOwnerMembership->id]);
        $this->assertDatabaseHas('memberships', ['id' => $ownerMembership->id, 'deleted_at' => null]);
    }

    public function test_member_can_leave_and_loses_access_and_owner_cannot_manage_foreign_memberships(): void
    {
        [$owner, $household] = $this->householdOwner();
        [$member, $membership] = $this->householdMember($household);
        $foreignMembership = Membership::factory()->create();

        $this->signInAs($member)->postJson('/api/membership/leave')->assertNoContent();
        $this->assertSoftDeleted('memberships', ['id' => $membership->id]);
        $this->getJson('/api/items')->assertForbidden();

        $this->signInAs($owner)->deleteJson('/api/memberships/'.$foreignMembership->id)->assertNotFound();
    }

    public function test_invitation_creation_normalizes_email_and_never_exposes_the_token_or_hash(): void
    {
        [$owner, $household] = $this->householdOwner();
        $response = $this->signInAs($owner)->postJson('/api/household-invitations', [
            'data' => ['email' => '  NEW.Person@Example.COM  '],
        ]);
        $invitation = HouseholdInvitation::query()->where('household_id', $household->id)->firstOrFail();
        $token = substr((string) $response->json('data.invitation_url'), strlen(route('invitations.accept').'#token='));

        $response->assertCreated()
            ->assertJsonPath('data.email', 'new.person@example.com')
            ->assertJsonPath('data.role', 'member')
            ->assertJsonStructure(['data' => ['type', 'id', 'email', 'role', 'created_at', 'expires_at', 'accepted_at', 'revoked_at', 'is_expired', 'invitation_url']])
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.token_hash');
        $this->assertSame(hash('sha256', $token), $invitation->token_hash);
        $this->assertSame(64, strlen($token));
        $this->assertDatabaseHas('household_invitations', ['id' => $invitation->id, 'email' => 'new.person@example.com']);

        $this->getJson('/api/household-invitations')->assertOk()
            ->assertJsonMissingPath('data.0.invitation_url')
            ->assertJsonMissingPath('data.0.token_hash');
        $this->getJson('/api/household-invitations?include=inviter')->assertBadRequest();
        $this->postJson('/api/household-invitations', ['data' => ['email' => 'new.person@example.com']])
            ->assertConflict()->assertJsonPath('errors.0.code', 'active_invitation_exists');
    }

    public function test_invitation_creation_distinguishes_current_and_foreign_membership_conflicts_including_archived_rows(): void
    {
        [$owner, $household] = $this->householdOwner();
        [$currentMember, $currentMembership] = $this->householdMember($household);
        $foreignHousehold = Household::factory()->create();
        [$formerMember, $formerMembership] = $this->householdMember($foreignHousehold);
        $formerMembership->delete();
        $this->signInAs($owner);

        $this->postJson('/api/household-invitations', ['data' => ['email' => $currentMember->email]])
            ->assertConflict()->assertJsonPath('errors.0.code', 'invitation_already_member');
        $this->postJson('/api/household-invitations', ['data' => ['email' => $formerMember->email]])
            ->assertConflict()->assertJsonPath('errors.0.code', 'invitation_household_membership_exists');
        $this->assertDatabaseCount('household_invitations', 0);
    }

    public function test_expired_invitation_can_be_recreated_but_cannot_be_resent_while_another_invite_is_active(): void
    {
        [$owner, $household] = $this->householdOwner();
        $this->signInAs($owner)->postJson('/api/household-invitations', ['data' => ['email' => 'same@example.com']])->assertCreated();
        $expired = HouseholdInvitation::query()->firstOrFail();
        $expired->forceFill(['expires_at' => now()->subSecond()])->save();

        $created = $this->postJson('/api/household-invitations', ['data' => ['email' => 'same@example.com']])->assertCreated();
        $newInvitationId = $created->json('data.id');
        $this->assertNotSame((string) $expired->id, (string) $newInvitationId);
        $this->postJson('/api/household-invitations/'.$expired->id.'/resend')->assertConflict()
            ->assertJsonPath('errors.0.code', 'active_invitation_exists');
        $this->assertSame(2, $household->invitations()->count());
    }

    public function test_invitation_resend_rotates_secret_and_revocation_prevents_acceptance(): void
    {
        [$owner, $household] = $this->householdOwner();
        $this->signInAs($owner);
        $created = $this->postJson('/api/household-invitations', ['data' => ['email' => 'invite@example.com']]);
        $invitation = HouseholdInvitation::query()->where('household_id', $household->id)->firstOrFail();
        $oldToken = substr((string) $created->json('data.invitation_url'), strlen(route('invitations.accept').'#token='));

        $resent = $this->postJson('/api/household-invitations/'.$invitation->id.'/resend');
        $newToken = substr((string) $resent->json('data.invitation_url'), strlen(route('invitations.accept').'#token='));
        $resent->assertOk()->assertJsonPath('data.id', (string) $invitation->id);
        $this->assertNotSame($oldToken, $newToken);
        $this->assertSame(hash('sha256', $newToken), $invitation->fresh()->token_hash);
        $this->postJson('/api/household-invitations/accept', ['data' => ['token' => $oldToken]])
            ->assertConflict()->assertJsonPath('errors.0.code', 'invitation_unavailable');

        $this->deleteJson('/api/household-invitations/'.$invitation->id)->assertNoContent();
        $this->postJson('/api/household-invitations/accept', ['data' => ['token' => $newToken]])
            ->assertConflict()->assertJsonPath('errors.0.code', 'invitation_unavailable');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_valid_guest_acceptance_creates_membership_and_logs_in_with_household_include(): void
    {
        [$owner, $household] = $this->householdOwner();
        $this->signInAs($owner)->withHeader('Origin', 'http://localhost')->postJson('/api/household-invitations', ['data' => ['email' => 'guest@example.com']]);
        $invitation = HouseholdInvitation::query()->firstOrFail();
        $token = $this->invitationToken($invitation);

        Auth::guard('web')->logout();
        Auth::forgetGuards();
        $response = $this->withHeader('Origin', 'http://localhost')->postJson('/api/household-invitations/accept?include=membership.household', [
            'data' => [
                'token' => $token,
                'email' => 'GUEST@example.com',
                'name' => 'Guest User',
                'password' => 'GoodPassword123!',
                'password_confirmation' => 'GoodPassword123!',
            ],
        ]);

        $user = User::query()->where('email', 'guest@example.com')->firstOrFail();
        $membership = Membership::query()->where('user_id', $user->id)->firstOrFail();
        $response->assertOk()
            ->assertJsonPath('data.id', (string) $user->id)
            ->assertJsonPath('data.membership.id', (string) $membership->id)
            ->assertJsonPath('data.membership.household.id', (string) $household->id)
            ->assertJsonMissingPath('data.password');
        $this->assertDatabaseHas('household_invitations', ['id' => $invitation->id, 'accepted_at' => $invitation->fresh()->accepted_at]);
        $this->assertAuthenticatedAs($user, 'web');
        $this->postJson('/api/household-invitations/accept', ['data' => ['token' => $token]])
            ->assertConflict()->assertJsonPath('errors.0.code', 'invitation_unavailable');
    }

    public function test_authenticated_existing_account_can_accept_only_its_matching_invitation(): void
    {
        [$owner, $household] = $this->householdOwner();
        $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
        $this->signInAs($owner)->postJson('/api/household-invitations', ['data' => ['email' => $invitedUser->email]])->assertCreated();
        $invitation = HouseholdInvitation::query()->firstOrFail();
        $token = $this->invitationToken($invitation);

        $otherUser = User::factory()->create(['email' => 'other@example.com']);
        $this->signInAs($otherUser)->withHeader('Origin', 'http://localhost')
            ->postJson('/api/household-invitations/accept', ['data' => ['token' => $token]])
            ->assertConflict()->assertJsonPath('errors.0.code', 'invitation_email_mismatch');

        $this->signInAs($invitedUser)->withHeader('Origin', 'http://localhost')
            ->postJson('/api/household-invitations/accept?include=membership.household', ['data' => ['token' => $token]])
            ->assertOk()
            ->assertJsonPath('data.email', $invitedUser->email)
            ->assertJsonPath('data.membership.household.id', (string) $household->id);

        $this->assertDatabaseHas('memberships', ['user_id' => $invitedUser->id, 'household_id' => $household->id, 'role' => 'member']);
        $this->assertDatabaseHas('household_invitations', ['id' => $invitation->id, 'accepted_at' => $invitation->fresh()->accepted_at]);
    }

    public function test_guest_acceptance_requires_matching_email_and_invalid_includes_do_not_create_accounts(): void
    {
        [$owner] = $this->householdOwner();
        $this->signInAs($owner)->withHeader('Origin', 'http://localhost')->postJson('/api/household-invitations', ['data' => ['email' => 'guest@example.com']]);
        $invitation = HouseholdInvitation::query()->firstOrFail();
        $token = $this->invitationToken($invitation);
        Auth::guard('web')->logout();
        Auth::forgetGuards();

        $this->withHeader('Origin', 'http://localhost')->postJson('/api/household-invitations/accept?include=secret', [
            'data' => ['token' => $token, 'email' => 'guest@example.com', 'name' => 'Guest', 'password' => 'GoodPassword123!', 'password_confirmation' => 'GoodPassword123!'],
        ])->assertBadRequest();
        $this->withHeader('Origin', 'http://localhost')->postJson('/api/household-invitations/accept', [
            'data' => ['token' => $token, 'email' => 'other@example.com', 'name' => 'Guest', 'password' => 'GoodPassword123!', 'password_confirmation' => 'GoodPassword123!'],
        ])->assertConflict()->assertJsonPath('errors.0.code', 'invitation_email_mismatch');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('memberships', 1);
        $this->assertNull($invitation->fresh()->accepted_at);
    }

    public function test_invitation_acceptance_rejects_existing_accounts_and_expired_links(): void
    {
        [$owner] = $this->householdOwner();
        $otherHousehold = Household::factory()->create();
        $this->signInAs($owner)->withHeader('Origin', 'http://localhost')->postJson('/api/household-invitations', ['data' => ['email' => 'existing@example.com']]);
        $invitation = HouseholdInvitation::query()->firstOrFail();
        $token = $this->invitationToken($invitation);
        $member = User::factory()->create(['email' => 'existing@example.com']);
        Membership::factory()->for($otherHousehold)->for($member)->create();
        Auth::guard('web')->logout();
        Auth::forgetGuards();

        $this->withHeader('Origin', 'http://localhost')->postJson('/api/household-invitations/accept', [
            'data' => ['token' => $token, 'email' => 'existing@example.com', 'name' => 'Name', 'password' => 'GoodPassword123!', 'password_confirmation' => 'GoodPassword123!'],
        ])->assertConflict()->assertJsonPath('errors.0.code', 'invitation_account_exists');

        $this->travel(8)->days();
        $this->withHeader('Origin', 'http://localhost')->postJson('/api/household-invitations/accept', [
            'data' => ['token' => $token, 'email' => 'existing@example.com', 'name' => 'Name', 'password' => 'GoodPassword123!', 'password_confirmation' => 'GoodPassword123!'],
        ])->assertConflict()->assertJsonPath('errors.0.code', 'invitation_unavailable');
        $this->assertNull($invitation->fresh()->accepted_at);
    }

    /** @return array{User, Household, Membership} */
    private function householdOwner(): array
    {
        $household = Household::factory()->create(['name' => 'Home']);
        $owner = User::factory()->create();
        $membership = Membership::factory()->for($household)->for($owner)->owner()->create();

        return [$owner, $household, $membership];
    }

    /** @return array{User, Membership} */
    private function householdMember(Household $household): array
    {
        $member = User::factory()->create();
        $membership = Membership::factory()->for($household)->for($member)->create();

        return [$member, $membership];
    }

    private function invitationToken(HouseholdInvitation $invitation): string
    {
        $this->signInAs($invitation->inviter);
        $response = $this->postJson('/api/household-invitations/'.$invitation->id.'/resend');

        return substr((string) $response->json('data.invitation_url'), strlen(route('invitations.accept').'#token='));
    }

    private function signInAs(User $user): static
    {
        Auth::forgetGuards();

        return $this->actingAs($user, 'web');
    }
}
