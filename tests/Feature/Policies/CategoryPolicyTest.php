<?php

namespace Tests\Feature\Policies;

use App\Enums\MembershipRole;
use App\Models\Category;
use App\Models\Household;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CategoryPolicyTest extends TestCase
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
        $category = $household->categories()->create(['name' => 'Kitchen']);
        $archivedCategory = $household->categories()->create(['name' => 'Seasonal']);
        $archivedCategory->delete();
        $gate = Gate::forUser($user);

        $this->assertTrue($gate->allows('viewAny', Category::class));
        $this->assertTrue($gate->allows('create', Category::class));
        $this->assertTrue($gate->allows('view', $category));
        $this->assertTrue($gate->allows('update', $category));
        $this->assertTrue($gate->allows('delete', $category));
        $this->assertTrue($gate->allows('restore', $archivedCategory));
        $this->assertSame($role === MembershipRole::Owner, $gate->allows('forceDelete', $category));
    }

    public function test_users_without_an_active_household_membership_cannot_catalog_write(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->create();

        $this->assertFalse(Gate::forUser($user)->allows('viewAny', Category::class));
        $this->assertFalse(Gate::forUser($user)->allows('create', Category::class));
        $this->assertFalse(Gate::forUser($user)->allows('view', $category));
        $this->assertFalse(Gate::forUser($user)->allows('update', $category));
        $this->assertFalse(Gate::forUser($user)->allows('delete', $category));
        $this->assertFalse(Gate::forUser($user)->allows('restore', $category));
    }
}
