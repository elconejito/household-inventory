<?php

namespace App\Actions;

use App\Enums\MembershipRole;
use App\Exceptions\HouseholdAdministrationConflict;
use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ManageHouseholdInvitations
{
    public function create(Household $household, User $inviter, string $email): HouseholdInvitation
    {
        return DB::transaction(function () use ($household, $inviter, $email): HouseholdInvitation {
            $lockedHousehold = Household::query()->lockForUpdate()->findOrFail($household->getKey());
            $this->assertOwner($lockedHousehold, $inviter);
            $this->assertEmailCanBeInvited($lockedHousehold, $email);

            $duplicateExists = $lockedHousehold->invitations()
                ->where('email', $email)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->exists();

            if ($duplicateExists) {
                throw new HouseholdAdministrationConflict('active_invitation_exists', 'An active invitation already exists for this email address.');
            }

            $token = $this->newToken();
            $invitation = $lockedHousehold->invitations()->create([
                'email' => $email,
                'role' => MembershipRole::Member,
                'token_hash' => hash('sha256', $token),
                'invited_by' => $inviter->getKey(),
                'created_at' => now(),
                'expires_at' => now()->addDays(7),
            ]);

            return $this->withUrl($invitation, $token);
        });
    }

    public function resend(Household $household, HouseholdInvitation $invitation, User $actor): HouseholdInvitation
    {
        return DB::transaction(function () use ($household, $invitation, $actor): HouseholdInvitation {
            $lockedHousehold = Household::query()->lockForUpdate()->findOrFail($household->getKey());
            $this->assertOwner($lockedHousehold, $actor);
            $lockedInvitation = $lockedHousehold->invitations()->lockForUpdate()->findOrFail($invitation->getKey());

            if ($lockedInvitation->accepted_at !== null || $lockedInvitation->revoked_at !== null) {
                throw new HouseholdAdministrationConflict('invitation_unavailable', 'This invitation can no longer be resent.');
            }

            $this->assertEmailCanBeInvited($lockedHousehold, $lockedInvitation->email);
            $anotherActiveInvitationExists = $lockedHousehold->invitations()
                ->where('id', '!=', $lockedInvitation->getKey())
                ->where('email', $lockedInvitation->email)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->exists();

            if ($anotherActiveInvitationExists) {
                throw new HouseholdAdministrationConflict('active_invitation_exists', 'An active invitation already exists for this email address.');
            }

            $token = $this->newToken();
            $lockedInvitation->forceFill([
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addDays(7),
            ])->save();

            return $this->withUrl($lockedInvitation, $token);
        });
    }

    public function revoke(Household $household, HouseholdInvitation $invitation, User $actor): void
    {
        DB::transaction(function () use ($household, $invitation, $actor): void {
            $lockedHousehold = Household::query()->lockForUpdate()->findOrFail($household->getKey());
            $this->assertOwner($lockedHousehold, $actor);
            $lockedInvitation = $lockedHousehold->invitations()->lockForUpdate()->findOrFail($invitation->getKey());

            if ($lockedInvitation->accepted_at !== null) {
                throw new HouseholdAdministrationConflict('invitation_unavailable', 'An accepted invitation can no longer be revoked.');
            }

            if ($lockedInvitation->accepted_at === null && $lockedInvitation->revoked_at === null) {
                $lockedInvitation->forceFill(['revoked_at' => now()])->save();
            }
        });
    }

    /** @param array{email?: string, name?: string, password?: string} $data */
    public function accept(string $token, ?User $authenticatedUser, array $data): User
    {
        $tokenHash = hash('sha256', $token);
        $invitationSummary = HouseholdInvitation::query()->where('token_hash', $tokenHash)->first();

        if ($invitationSummary === null) {
            throw new HouseholdAdministrationConflict('invitation_unavailable', 'This invitation is invalid or no longer available.');
        }

        try {
            return DB::transaction(function () use ($tokenHash, $authenticatedUser, $data, $invitationSummary): User {
                $household = Household::query()->lockForUpdate()->findOrFail($invitationSummary->household_id);
                $invitation = $household->invitations()
                    ->lockForUpdate()
                    ->where('token_hash', $tokenHash)
                    ->first();

                if ($invitation === null || ! $invitation->isActive()) {
                    throw new HouseholdAdministrationConflict('invitation_unavailable', 'This invitation is invalid, expired, or no longer available.');
                }

                $user = $authenticatedUser;

                if ($user !== null) {
                    $user = User::query()->lockForUpdate()->findOrFail($user->getKey());

                    if (Str::lower($user->email) !== $invitation->email) {
                        throw new HouseholdAdministrationConflict('invitation_email_mismatch', 'Sign in with the email address this invitation was sent to.');
                    }
                } else {
                    if (Str::lower((string) ($data['email'] ?? '')) !== $invitation->email) {
                        throw new HouseholdAdministrationConflict('invitation_email_mismatch', 'The email address must match the invitation.');
                    }

                    if (User::query()->where('email', $invitation->email)->exists()) {
                        throw new HouseholdAdministrationConflict('invitation_account_exists', 'An account already exists for this email. Sign in to accept the invitation.');
                    }

                    $user = User::query()->create([
                        'email' => $invitation->email,
                        'name' => $data['name'],
                        'password' => $data['password'],
                    ]);
                }

                $existingMembership = Membership::withTrashed()->where('user_id', $user->getKey())->first();
                if ($existingMembership !== null) {
                    $code = $existingMembership->household_id === $household->getKey()
                        ? 'invitation_already_member'
                        : 'invitation_household_membership_exists';
                    $detail = $code === 'invitation_already_member'
                        ? 'This account already has a membership in this household.'
                        : 'This account already belongs to another household.';

                    throw new HouseholdAdministrationConflict($code, $detail);
                }

                try {
                    $household->memberships()->create([
                        'user_id' => $user->getKey(),
                        'role' => MembershipRole::Member,
                        'joined_at' => now(),
                    ]);
                } catch (UniqueConstraintViolationException $exception) {
                    throw new HouseholdAdministrationConflict('invitation_household_membership_exists', 'This account already belongs to a household.');
                }

                $invitation->forceFill(['accepted_at' => now()])->save();

                return $user->load('memberships.household');
            });
        } catch (UniqueConstraintViolationException $exception) {
            if ($authenticatedUser === null) {
                throw new HouseholdAdministrationConflict('invitation_account_exists', 'An account already exists for this email. Sign in to accept the invitation.');
            }

            throw $exception;
        }
    }

    private function assertEmailCanBeInvited(Household $household, string $email): void
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            return;
        }

        $membership = Membership::withTrashed()->where('user_id', $user->getKey())->first();
        if ($membership === null) {
            return;
        }

        if ($membership->household_id === $household->getKey()) {
            throw new HouseholdAdministrationConflict('invitation_already_member', 'This email address already belongs to this household.');
        }

        throw new HouseholdAdministrationConflict('invitation_household_membership_exists', 'This email address already belongs to another household.');
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

    private function newToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function withUrl(HouseholdInvitation $invitation, string $token): HouseholdInvitation
    {
        $invitation->setAttribute('invitation_url', route('invitations.accept').'#token='.$token);

        return $invitation;
    }
}
