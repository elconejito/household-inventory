<?php

namespace Tests\Feature;

use App\Actions\ManageLocationHierarchy;
use App\Models\Household;
use App\Models\Item;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class InventoryCatalogIntegrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_item_totals_include_stock_directly_in_both_parent_and_child_locations(): void
    {
        [$user, $household, $item] = $this->inventoryContext();
        $hierarchy = app(ManageLocationHierarchy::class);
        $basement = $hierarchy->create($household, ['name' => 'Basement']);
        $shelf = $hierarchy->create($household, ['name' => 'Shelf', 'parent_id' => $basement->id]);
        $category = $household->categories()->create(['name' => 'Paper']);
        $item->categories()->attach($category);
        $this->actingAs($user, 'web');

        $this->restock($item, $basement->id, 150);
        $this->restock($item, $shelf->id, 15);

        $this->getJson('/api/items/'.$item->id)
            ->assertOk()
            ->assertJsonPath('data.total_quantity', 165)
            ->assertJsonMissingPath('data.inventory_levels');
        $this->getJson('/api/items/'.$item->id.'?include=inventory_levels.location')
            ->assertOk()
            ->assertJsonCount(2, 'data.inventory_levels')
            ->assertJsonPath('data.inventory_levels.0.location.name', 'Basement')
            ->assertJsonMissingPath('data.inventory_levels.0.data');
        $this->getJson('/api/items?include=inventory_levels')
            ->assertOk()
            ->assertJsonPath('data.0.total_quantity', 165)
            ->assertJsonCount(2, 'data.0.inventory_levels')
            ->assertJsonMissingPath('data.0.inventory_levels.0.location');
        $this->getJson('/api/categories/'.$category->id.'?include=items')
            ->assertOk()
            ->assertJsonPath('data.items.0.total_quantity', 165);
        $this->getJson('/api/inventory-levels?filter[item_id]='.$item->id.'&include=item')
            ->assertOk()
            ->assertJsonPath('data.0.item.total_quantity', 165);
    }

    public function test_positive_stock_blocks_item_and_location_archive_with_409_until_corrected_to_zero(): void
    {
        [$user, $household, $item] = $this->inventoryContext();
        $location = $household->locations()->create(['name' => 'Basement']);
        $this->actingAs($user, 'web');
        $this->restock($item, $location->id, 5);

        $this->deleteJson('/api/items/'.$item->id)->assertConflict();
        $this->deleteJson('/api/locations/'.$location->id)->assertConflict();
        $this->assertNotSoftDeleted($item);
        $this->assertNotSoftDeleted($location);

        $this->postJson('/api/inventory-movements', ['data' => [
            'movement_type' => 'correction',
            'item_id' => $item->id,
            'location_id' => $location->id,
            'observed_quantity' => 0,
        ]])->assertCreated();
        $this->deleteJson('/api/locations/'.$location->id)->assertNoContent();
        $this->getJson('/api/items/'.$item->id.'?include=inventory_levels.location')
            ->assertOk()
            ->assertJsonPath('data.total_quantity', 0)
            ->assertJsonCount(0, 'data.inventory_levels');
        $this->getJson('/api/items?include=inventory_levels.location')
            ->assertOk()
            ->assertJsonCount(0, 'data.0.inventory_levels');
        $this->deleteJson('/api/items/'.$item->id)->assertNoContent();
        $this->assertSoftDeleted($item);
        $this->assertDatabaseCount('inventory_levels', 1);
        $this->assertDatabaseCount('inventory_movements', 2);
    }

    public function test_transfer_preserves_existing_destination_monitoring_and_household_total(): void
    {
        [$user, $household, $item] = $this->inventoryContext();
        $source = $household->locations()->create(['name' => 'Basement']);
        $destination = $household->locations()->create(['name' => 'Pantry']);
        $this->actingAs($user, 'web');
        $this->restock($item, $source->id, 60);
        $this->postJson('/api/inventory-levels', ['data' => [
            'item_id' => $item->id,
            'location_id' => $destination->id,
            'alert_threshold' => 10,
        ]])->assertCreated();

        $this->postJson('/api/inventory-movements', ['data' => [
            'movement_type' => 'transfer',
            'item_id' => $item->id,
            'source_location_id' => $source->id,
            'destination_location_id' => $destination->id,
            'quantity' => 30,
        ]])->assertCreated();

        $this->getJson('/api/items/'.$item->id)
            ->assertOk()
            ->assertJsonPath('data.total_quantity', 60);
        $this->assertDatabaseHas('inventory_levels', [
            'item_id' => $item->id,
            'location_id' => $destination->id,
            'quantity' => 30,
            'alert_threshold' => 10,
        ]);
    }

    /**
     * @return array{User, Household, Item}
     */
    private function inventoryContext(): array
    {
        $user = User::factory()->create();
        $household = Household::factory()->create();
        Membership::factory()->for($household)->for($user)->create();
        $item = Item::factory()->for($household)->create(['name' => 'Toilet paper', 'counting_unit' => 'roll']);

        return [$user, $household, $item];
    }

    private function restock(Item $item, int $locationId, int $quantity): void
    {
        $this->postJson('/api/inventory-movements', ['data' => [
            'movement_type' => 'restock',
            'item_id' => $item->id,
            'location_id' => $locationId,
            'quantity' => $quantity,
        ]])->assertCreated();
    }
}
