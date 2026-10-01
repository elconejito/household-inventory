<?php

namespace Tests\Feature\Policies;

use App\Enums\MembershipRole;
use App\Models\Household;
use App\Models\Item;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ItemPolicyTest extends TestCase
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
    public function test_all_household_roles_can_catalog_write(MembershipRole $role): void
    {
        $user = User::factory()->create();
        $household = Household::factory()->create();
        Membership::factory()->for($household)->for($user)->create(['role' => $role]);
        $item = Item::factory()->for($household)->create();
        $archivedItem = Item::factory()->for($household)->create();
        $archivedItem->delete();
        $gate = Gate::forUser($user);

        $this->assertTrue($gate->allows('viewAny', Item::class));
        $this->assertTrue($gate->allows('create', Item::class));
        $this->assertTrue($gate->allows('view', $item));
        $this->assertTrue($gate->allows('update', $item));
        $this->assertTrue($gate->allows('delete', $item));
        $this->assertTrue($gate->allows('restore', $archivedItem));
        $this->assertFalse($gate->allows('forceDelete', $item));
    }

    public function test_users_without_an_active_household_membership_cannot_catalog_write(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->create();

        $this->assertFalse(Gate::forUser($user)->allows('viewAny', Item::class));
        $this->assertFalse(Gate::forUser($user)->allows('create', Item::class));
        $this->assertFalse(Gate::forUser($user)->allows('view', $item));
        $this->assertFalse(Gate::forUser($user)->allows('update', $item));
        $this->assertFalse(Gate::forUser($user)->allows('delete', $item));
        $this->assertFalse(Gate::forUser($user)->allows('restore', $item));
    }
}
