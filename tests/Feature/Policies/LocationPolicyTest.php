<?php

namespace Tests\Feature\Policies;

use App\Enums\MembershipRole;
use App\Models\Household;
use App\Models\Location;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LocationPolicyTest extends TestCase
{
    use LazilyRefreshDatabase;

    public static function householdRoles(): array
    {
        return [
            'owner' => [MembershipRole::Owner],
            'member' => [MembershipRole::Member],
        ];
    }

    #[DataProvider('householdRoles')]
    public function test_all_household_roles_can_manage_locations(MembershipRole $role): void
    {
        $user = User::factory()->create();
        $household = Household::factory()->create();
        Membership::factory()->for($household)->for($user)->create(['role' => $role]);
        $location = $household->locations()->create(['name' => 'Basement']);
        $archivedLocation = $household->locations()->create(['name' => 'Seasonal']);
        $archivedLocation->delete();
        $gate = Gate::forUser($user);

        $this->assertTrue($gate->allows('viewAny', Location::class));
        $this->assertTrue($gate->allows('create', Location::class));
        $this->assertTrue($gate->allows('view', $location));
        $this->assertTrue($gate->allows('update', $location));
        $this->assertTrue($gate->allows('delete', $location));
        $this->assertTrue($gate->allows('restore', $archivedLocation));
        $this->assertFalse($gate->allows('forceDelete', $location));
    }

    public function test_users_without_an_active_household_membership_cannot_manage_locations(): void
    {
        $user = User::factory()->create();
        $location = Location::factory()->create();

        $this->assertFalse(Gate::forUser($user)->allows('viewAny', Location::class));
        $this->assertFalse(Gate::forUser($user)->allows('create', Location::class));
        $this->assertFalse(Gate::forUser($user)->allows('view', $location));
        $this->assertFalse(Gate::forUser($user)->allows('update', $location));
        $this->assertFalse(Gate::forUser($user)->allows('delete', $location));
        $this->assertFalse(Gate::forUser($user)->allows('restore', $location));
    }
}
