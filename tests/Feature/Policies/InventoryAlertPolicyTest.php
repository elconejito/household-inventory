<?php

namespace Tests\Feature\Policies;

use App\Enums\InventoryAlertType;
use App\Enums\MembershipRole;
use App\Models\Household;
use App\Models\InventoryAlert;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InventoryAlertPolicyTest extends TestCase
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
    public function test_household_roles_can_view_create_and_resolve_alerts(MembershipRole $role): void
    {
        $user = User::factory()->create();
        $household = Household::factory()->create();
        Membership::factory()->for($household)->for($user)->create(['role' => $role]);
        $item = $household->items()->create(['name' => 'Paper', 'counting_unit' => 'roll']);
        $alert = $household->inventoryAlerts()->create([
            'item_id' => $item->id,
            'alert_type' => InventoryAlertType::BuySoon,
            'created_by' => $user->id,
            'created_at' => now(),
        ]);
        $gate = Gate::forUser($user);

        $this->assertTrue($gate->allows('viewAny', InventoryAlert::class));
        $this->assertTrue($gate->allows('create', InventoryAlert::class));
        $this->assertTrue($gate->allows('view', $alert));
        $this->assertTrue($gate->allows('resolve', $alert));
    }

    public function test_outsiders_and_unaffiliated_users_cannot_access_alert_policy_abilities(): void
    {
        $household = Household::factory()->create();
        $creator = User::factory()->create();
        Membership::factory()->for($household)->for($creator)->create();
        $item = $household->items()->create(['name' => 'Soap', 'counting_unit' => 'bar']);
        $alert = $household->inventoryAlerts()->create([
            'item_id' => $item->id,
            'alert_type' => InventoryAlertType::BuySoon,
            'created_by' => $creator->id,
            'created_at' => now(),
        ]);
        $outsider = User::factory()->create();
        $unaffiliated = User::factory()->create();
        $outsiderHousehold = Household::factory()->create();
        Membership::factory()->for($outsiderHousehold)->for($outsider)->create();

        $outsiderGate = Gate::forUser($outsider);
        $this->assertTrue($outsiderGate->allows('viewAny', InventoryAlert::class));
        $this->assertTrue($outsiderGate->allows('create', InventoryAlert::class));
        $this->assertFalse($outsiderGate->allows('view', $alert));
        $this->assertFalse($outsiderGate->allows('resolve', $alert));

        $unaffiliatedGate = Gate::forUser($unaffiliated);
        $this->assertFalse($unaffiliatedGate->allows('viewAny', InventoryAlert::class));
        $this->assertFalse($unaffiliatedGate->allows('create', InventoryAlert::class));
        $this->assertFalse($unaffiliatedGate->allows('view', $alert));
        $this->assertFalse($unaffiliatedGate->allows('resolve', $alert));
    }
}
