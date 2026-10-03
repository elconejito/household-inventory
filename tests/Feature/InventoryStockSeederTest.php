<?php

namespace Tests\Feature;

use App\Actions\CreateInventoryAlert;
use App\Actions\CreateInventoryMovement;
use App\Enums\InventoryAlertType;
use App\Models\Category;
use App\Models\Household;
use App\Models\InventoryAlert;
use App\Models\InventoryLevel;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementEntry;
use App\Models\Item;
use App\Models\Location;
use App\Models\Membership;
use App\Models\User;
use Database\Seeders\InventoryStockSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use LogicException;
use Tests\TestCase;

class InventoryStockSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_standalone_seeder_creates_a_real_sample_movement_chain_once(): void
    {
        $this->seed(InventoryStockSeeder::class);

        $household = Household::query()->where('name', 'Inventory Demo Household')->firstOrFail();
        $paper = $household->items()->where('name', 'Demo Toilet Paper')->firstOrFail();
        $soap = $household->items()->where('name', 'Demo Dish Soap')->firstOrFail();
        $basement = $household->locations()->where('name', 'Demo Basement')->firstOrFail();
        $pantry = $household->locations()->where('name', 'Demo Main Pantry')->firstOrFail();
        $bathroom = $household->locations()->where('name', 'Demo Bathroom 1')->firstOrFail();

        $this->assertSame(2, $household->items()->count());
        $this->assertSame(5, $household->locations()->count());
        $this->assertSame(1, $household->memberships()->count());
        $this->assertSame(['Demo Paper Goods'], $paper->categories()->pluck('name')->all());
        $this->assertNull($pantry->parent_id);
        $this->assertDatabaseHas('inventory_levels', [
            'item_id' => $paper->getKey(),
            'location_id' => $basement->getKey(),
            'quantity' => 12,
            'alert_threshold' => 12,
        ]);
        $this->assertDatabaseHas('inventory_levels', [
            'item_id' => $paper->getKey(),
            'location_id' => $pantry->getKey(),
            'quantity' => 6,
            'alert_threshold' => null,
        ]);
        $this->assertDatabaseHas('inventory_levels', [
            'item_id' => $paper->getKey(),
            'location_id' => $bathroom->getKey(),
            'quantity' => 1,
            'alert_threshold' => null,
        ]);
        $this->assertSame(1, InventoryLevel::query()->where('item_id', $paper->getKey())->whereNotNull('alert_threshold')->count());
        $this->assertSame(6, InventoryMovement::query()->where('item_id', $paper->getKey())->count());
        $this->assertSame(10, InventoryMovementEntry::query()->count());
        $this->assertDatabaseHas('inventory_movements', ['item_id' => $paper->getKey(), 'movement_type' => 'consumption']);
        $this->assertDatabaseHas('inventory_movements', ['item_id' => $paper->getKey(), 'movement_type' => 'transfer']);
        $this->assertDatabaseHas('inventory_alerts', [
            'item_id' => $soap->getKey(),
            'alert_type' => InventoryAlertType::BuySoon->value,
            'resolved_at' => null,
        ]);

        $this->seed(InventoryStockSeeder::class);

        $demoUserId = User::query()->where('email', 'inventory-demo@example.test')->value('id');
        $this->assertSame(1, Household::query()->where('name', 'Inventory Demo Household')->count());
        $this->assertSame(1, Membership::query()->where('user_id', $demoUserId)->count());
        $this->assertSame(6, InventoryMovement::query()->where('item_id', $paper->getKey())->count());
        $this->assertSame(10, InventoryMovementEntry::query()->count());
        $this->assertSame(1, InventoryAlert::query()->where('item_id', $soap->getKey())->count());
        $this->assertSame(1, $paper->inventoryLevels()->where('location_id', $bathroom->getKey())->value('quantity'));
    }

    public function test_seeder_uses_the_demo_users_existing_membership_without_creating_household_history(): void
    {
        $user = User::factory()->create(['email' => 'inventory-demo@example.test']);
        $household = Household::factory()->create(['name' => 'Existing Demo Household']);
        $membership = Membership::factory()->for($household)->for($user)->create();

        $this->seed(InventoryStockSeeder::class);

        $this->assertSame(1, Household::query()->count());
        $this->assertSame(1, Membership::query()->where('user_id', $user->getKey())->count());
        $this->assertDatabaseHas('items', [
            'household_id' => $household->getKey(),
            'name' => 'Demo Toilet Paper',
        ]);
        $this->assertSame($household->getKey(), $membership->fresh()->household_id);
    }

    public function test_seeder_does_not_attach_the_demo_user_to_an_unrelated_same_name_household(): void
    {
        $unrelatedHousehold = Household::factory()->create(['name' => 'Inventory Demo Household']);

        $this->seed(InventoryStockSeeder::class);

        $demoUser = User::query()->where('email', 'inventory-demo@example.test')->firstOrFail();
        $demoMembership = $demoUser->memberships()->firstOrFail();

        $this->assertNotSame($unrelatedHousehold->getKey(), $demoMembership->household_id);
        $this->assertSame(2, Household::query()->where('name', 'Inventory Demo Household')->count());
        $this->assertSame(0, $unrelatedHousehold->items()->count());
        $this->assertSame(0, $unrelatedHousehold->memberships()->count());
    }

    public function test_seeder_refuses_production_before_creating_demo_records(): void
    {
        $this->app['env'] = 'production';

        try {
            $this->app->make(InventoryStockSeeder::class)->run(
                $this->app->make(CreateInventoryMovement::class),
                $this->app->make(CreateInventoryAlert::class),
            );
            $this->fail('The production seeder should refuse to run.');
        } catch (LogicException $exception) {
            $this->assertSame('Inventory demo data can only be seeded in local or testing environments.', $exception->getMessage());
        }

        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Household::query()->count());
        $this->assertSame(0, Item::query()->count());
        $this->assertSame(0, Category::query()->count());
        $this->assertSame(0, Location::query()->count());
        $this->assertSame(0, InventoryMovement::query()->count());
        $this->assertSame(0, InventoryAlert::query()->count());
    }

    public function test_seeder_does_not_create_a_new_household_when_demo_membership_history_is_archived(): void
    {
        $user = User::factory()->create(['email' => 'inventory-demo@example.test']);
        $household = Household::factory()->create(['name' => 'Former Demo Household']);
        $membership = Membership::factory()->for($household)->for($user)->create();
        $membership->delete();

        try {
            $this->app->make(InventoryStockSeeder::class)->run(
                $this->app->make(CreateInventoryMovement::class),
                $this->app->make(CreateInventoryAlert::class),
            );
            $this->fail('The seeder must not create a new household for a demo user with archived membership history.');
        } catch (LogicException $exception) {
            $this->assertSame('The demo user has membership history but no active household.', $exception->getMessage());
        }

        $this->assertSame(1, Household::query()->count());
        $this->assertSame(1, Membership::withTrashed()->where('user_id', $user->getKey())->count());
        $this->assertSame(0, Item::query()->count());
    }
}
