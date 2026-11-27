<?php

namespace Tests\Feature;

use App\Actions\CreateInventoryAlert;
use App\Actions\CreateInventoryMovement;
use App\Actions\ResolveInventoryAlert;
use App\Models\Category;
use App\Models\Household;
use App\Models\InventoryAlert;
use App\Models\InventoryLevel;
use App\Models\InventoryMovement;
use App\Models\Item;
use App\Models\ItemImage;
use App\Models\Location;
use App\Models\Membership;
use App\Models\Note;
use App\Models\User;
use Database\Seeders\InventoryStockSeeder;
use Database\Seeders\ItemImageSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\TestCase;

class InventoryStockSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_seeder_creates_a_complete_demo_household_once(): void
    {
        Storage::fake('inventory-images', ['visibility' => 'private']);

        $this->seed(InventoryStockSeeder::class);

        $household = Household::query()->where('name', 'Inventory Demo Household')->firstOrFail();
        $paper = $household->items()->where('name', 'Toilet Paper')->firstOrFail();
        $mainPantry = $household->locations()->where('name', 'Main Pantry')->firstOrFail();
        $kitchen = $household->locations()->where('name', 'Kitchen')->firstOrFail();
        $sunscreen = $household->items()->where('name', 'Sunscreen')->firstOrFail();

        $this->assertSame(100, $household->items()->count());
        $this->assertSame(101, $household->items()->withTrashed()->count());
        $this->assertSame(15, $household->categories()->count());
        $this->assertSame(20, $household->locations()->count());
        $this->assertSame($kitchen->getKey(), $mainPantry->parent_id);
        $this->assertSame(['Paper Goods'], $paper->categories()->pluck('name')->all());
        $this->assertSame(3, $paper->inventoryLevels()->count());
        $this->assertTrue(InventoryLevel::query()->where('item_id', $paper->getKey())->where('quantity', '>', 0)->exists());
        $this->assertTrue(InventoryLevel::query()->where('quantity', 0)->exists());
        $this->assertTrue(InventoryLevel::query()->whereColumn('quantity', '<=', 'alert_threshold')->whereNotNull('alert_threshold')->exists());
        $this->assertSame(5, $household->inventoryAlerts()->whereNull('resolved_at')->count());
        $this->assertSame(1, $sunscreen->inventoryAlerts()->whereNotNull('resolved_at')->count());
        $this->assertSame(6, Note::query()->count());
        $this->assertSame(2, $household->memberships()->count());
        $this->assertSame(1, $household->invitations()->where('email', 'sam@example.test')->count());

        $image = $paper->images()->firstOrFail();
        $this->assertSame('Storage label', $image->caption);
        Storage::disk('inventory-images')->assertExists($image->thumbnail_path);
        Storage::disk('inventory-images')->assertExists($image->display_path);
    }

    public function test_seeder_is_idempotent_after_creating_the_demo_household(): void
    {
        Storage::fake('inventory-images', ['visibility' => 'private']);

        $this->seed(InventoryStockSeeder::class);
        $household = Household::query()->where('name', 'Inventory Demo Household')->firstOrFail();
        $counts = [
            'items' => $household->items()->withTrashed()->count(),
            'levels' => InventoryLevel::query()->count(),
            'movements' => InventoryMovement::query()->count(),
            'alerts' => InventoryAlert::query()->count(),
            'notes' => Note::query()->count(),
            'images' => ItemImage::query()->count(),
        ];

        $this->seed(InventoryStockSeeder::class);

        $this->assertSame($counts['items'], $household->items()->withTrashed()->count());
        $this->assertSame($counts['levels'], InventoryLevel::query()->count());
        $this->assertSame($counts['movements'], InventoryMovement::query()->count());
        $this->assertSame($counts['alerts'], InventoryAlert::query()->count());
        $this->assertSame($counts['notes'], Note::query()->count());
        $this->assertSame($counts['images'], ItemImage::query()->count());
    }

    public function test_seeder_upgrades_legacy_demo_records_without_replaying_their_history(): void
    {
        Storage::fake('inventory-images', ['visibility' => 'private']);
        $user = User::factory()->create(['email' => 'inventory-demo@example.test']);
        $household = Household::factory()->create(['name' => 'Existing Demo Household']);
        Membership::factory()->for($household)->for($user)->owner()->create();
        $legacyPaper = $household->items()->create(['name' => 'Demo Toilet Paper', 'counting_unit' => 'roll']);
        $legacyBasement = $household->locations()->create(['name' => 'Demo Basement']);
        $movement = app(CreateInventoryMovement::class)->create($household, $user, [
            'movement_type' => 'restock',
            'item_id' => $legacyPaper->getKey(),
            'location_id' => $legacyBasement->getKey(),
            'quantity' => 9,
        ]);

        $this->seed(InventoryStockSeeder::class);

        $paper = $legacyPaper->fresh();
        $basement = $legacyBasement->fresh();
        $this->assertSame('Toilet Paper', $paper->name);
        $this->assertSame('Basement', $basement->name);
        $this->assertSame($legacyPaper->getKey(), $paper->getKey());
        $this->assertSame(9, $paper->inventoryLevels()->where('location_id', $basement->getKey())->value('quantity'));
        $this->assertModelExists($movement);
        $this->assertSame($household->getKey(), $user->memberships()->firstOrFail()->household_id);
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
                $this->app->make(ResolveInventoryAlert::class),
                $this->app->make(ItemImageSeeder::class),
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
