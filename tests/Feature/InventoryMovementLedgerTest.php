<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\InventoryLevel;
use App\Models\InventoryMovement;
use App\Models\Item;
use App\Models\Membership;
use App\Models\User;
use App\Policies\InventoryLevelPolicy;
use App\Policies\InventoryMovementPolicy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class InventoryMovementLedgerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_restock_and_transfer_record_balances_and_keep_existing_destination_threshold(): void
    {
        [$user, $household, $item] = $this->inventoryContext();
        $source = $household->locations()->create(['name' => 'Basement']);
        $destination = $household->locations()->create(['name' => 'Pantry']);
        $this->actingAs($user, 'web');

        $this->postJson('/api/inventory-movements', ['data' => ['movement_type' => 'restock', 'item_id' => $item->id, 'location_id' => $source->id, 'quantity' => 20]])
            ->assertCreated()
            ->assertJsonPath('data.movement_type', 'restock')
            ->assertJsonMissingPath('data.entries');
        $this->postJson('/api/inventory-levels', ['data' => ['item_id' => $item->id, 'location_id' => $destination->id, 'alert_threshold' => 5]])->assertCreated();

        $this->postJson('/api/inventory-movements', ['data' => ['movement_type' => 'transfer', 'item_id' => $item->id, 'source_location_id' => $source->id, 'destination_location_id' => $destination->id, 'quantity' => 7]])
            ->assertCreated()
            ->assertJsonPath('data.movement_type', 'transfer');

        $this->getJson('/api/inventory-movements?filter[from_location_id]='.$source->id.'&include=item,entries.location,recorded_by')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.movement_type', 'transfer')
            ->assertJsonPath('data.0.item.total_quantity', 20)
            ->assertJsonPath('data.0.entries.0.location.id', (string) $source->id)
            ->assertJsonPath('data.0.recorded_by.id', (string) $user->id);

        $this->assertDatabaseHas('inventory_levels', ['item_id' => $item->id, 'location_id' => $source->id, 'quantity' => 13]);
        $this->assertDatabaseHas('inventory_levels', ['item_id' => $item->id, 'location_id' => $destination->id, 'quantity' => 7, 'alert_threshold' => 5]);
        $this->assertDatabaseCount('inventory_movement_entries', 3);
    }

    public function test_rejected_consumption_and_unchanged_correction_do_not_create_ledger_rows(): void
    {
        [$user, $household, $item] = $this->inventoryContext();
        $location = $household->locations()->create(['name' => 'Pantry']);
        $this->actingAs($user, 'web');

        $this->postJson('/api/inventory-movements', ['data' => ['movement_type' => 'consumption', 'item_id' => $item->id, 'location_id' => $location->id, 'quantity' => 1]])->assertUnprocessable();
        $this->postJson('/api/inventory-movements', ['data' => ['movement_type' => 'correction', 'item_id' => $item->id, 'location_id' => $location->id, 'observed_quantity' => 0, 'note' => null]])->assertUnprocessable();

        $this->assertDatabaseCount('inventory_levels', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('inventory_movement_entries', 0);
    }

    public function test_inventory_level_creation_is_zero_only_and_threshold_patch_preserves_quantity(): void
    {
        [$user, $household, $item] = $this->inventoryContext();
        $location = $household->locations()->create(['name' => 'Pantry']);
        $this->actingAs($user, 'web');
        $this->postJson('/api/inventory-movements', ['data' => ['movement_type' => 'restock', 'item_id' => $item->id, 'location_id' => $location->id, 'quantity' => 12]])->assertCreated();
        $level = InventoryLevel::query()->firstOrFail();

        $this->patchJson('/api/inventory-levels/'.$level->id, ['data' => ['alert_threshold' => 12]])
            ->assertOk()
            ->assertJsonPath('data.quantity', 12)
            ->assertJsonPath('data.alert_threshold', 12)
            ->assertJsonPath('data.stock_status', 'in_stock')
            ->assertJsonPath('data.alert_status', 'low');
        $this->patchJson('/api/inventory-levels/'.$level->id, ['data' => ['quantity' => 100]])->assertUnprocessable();
        $this->assertSame(12, $level->fresh()->quantity);
    }

    public function test_inventory_endpoints_hide_resources_from_another_household(): void
    {
        [$user] = $this->inventoryContext();
        $foreignUser = User::factory()->create();
        $foreignHousehold = Household::factory()->create();
        Membership::factory()->for($foreignHousehold)->for($foreignUser)->create();
        $foreignItem = Item::factory()->for($foreignHousehold)->create();
        $foreignLocation = $foreignHousehold->locations()->create(['name' => 'Garage']);
        $foreignLevel = InventoryLevel::factory()->create(['item_id' => $foreignItem->id, 'location_id' => $foreignLocation->id]);

        $this->actingAs($user, 'web')->getJson('/api/inventory-levels/'.$foreignLevel->id)->assertNotFound();
        $this->postJson('/api/inventory-movements', ['data' => ['movement_type' => 'restock', 'item_id' => $foreignItem->id, 'location_id' => $foreignLocation->id, 'quantity' => 4]])
            ->assertUnprocessable();
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_invalid_includes_are_rejected_before_a_movement_is_written(): void
    {
        [$user, $household, $item] = $this->inventoryContext();
        $location = $household->locations()->create(['name' => 'Pantry']);
        $this->actingAs($user, 'web');

        $this->postJson('/api/inventory-movements?include=secrets', ['data' => ['movement_type' => 'restock', 'item_id' => $item->id, 'location_id' => $location->id, 'quantity' => 5]])
            ->assertBadRequest();

        $this->assertDatabaseCount('inventory_levels', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('inventory_movement_entries', 0);
    }

    public function test_all_movement_types_apply_their_signed_ledger_effects(): void
    {
        [$user, $household, $item] = $this->inventoryContext();
        $source = $household->locations()->create(['name' => 'Basement']);
        $destination = $household->locations()->create(['name' => 'Pantry']);
        $this->actingAs($user, 'web');

        $this->postJson('/api/inventory-movements', ['data' => ['movement_type' => 'restock', 'item_id' => $item->id, 'location_id' => $source->id, 'quantity' => 8]])->assertCreated();
        $this->postJson('/api/inventory-movements', ['data' => ['movement_type' => 'correction', 'item_id' => $item->id, 'location_id' => $source->id, 'observed_quantity' => 5]])->assertCreated();
        $this->postJson('/api/inventory-movements', ['data' => ['movement_type' => 'consumption', 'item_id' => $item->id, 'location_id' => $source->id, 'quantity' => 1]])->assertCreated();
        $this->postJson('/api/inventory-movements', ['data' => ['movement_type' => 'disposal', 'item_id' => $item->id, 'location_id' => $source->id, 'quantity' => 1]])->assertCreated();
        $this->postJson('/api/inventory-movements', ['data' => ['movement_type' => 'transfer', 'item_id' => $item->id, 'source_location_id' => $source->id, 'destination_location_id' => $destination->id, 'quantity' => 1]])->assertCreated();

        $this->assertDatabaseHas('inventory_levels', ['item_id' => $item->id, 'location_id' => $source->id, 'quantity' => 2]);
        $this->assertDatabaseHas('inventory_levels', ['item_id' => $item->id, 'location_id' => $destination->id, 'quantity' => 1]);
        $this->assertDatabaseCount('inventory_movements', 5);
        $this->assertDatabaseCount('inventory_movement_entries', 6);
        $this->assertDatabaseHas('inventory_movement_entries', ['quantity_delta' => -3, 'balance_after' => 5]);
    }

    public function test_balance_overflow_is_rejected_without_a_partial_ledger_write(): void
    {
        [$user, $household, $item] = $this->inventoryContext();
        $location = $household->locations()->create(['name' => 'Warehouse']);
        $this->actingAs($user, 'web');

        $this->postJson('/api/inventory-movements', ['data' => ['movement_type' => 'restock', 'item_id' => $item->id, 'location_id' => $location->id, 'quantity' => 4294967295]])->assertCreated();
        $this->postJson('/api/inventory-movements', ['data' => ['movement_type' => 'restock', 'item_id' => $item->id, 'location_id' => $location->id, 'quantity' => 1]])->assertUnprocessable();

        $this->assertDatabaseHas('inventory_levels', ['item_id' => $item->id, 'location_id' => $location->id, 'quantity' => 4294967295]);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseCount('inventory_movement_entries', 1);
    }

    public function test_inventory_routes_return_401_without_authentication(): void
    {
        $level = InventoryLevel::factory()->create();
        $movement = InventoryMovement::factory()->create();
        $this->getJson('/api/inventory-levels')->assertUnauthorized();
        $this->postJson('/api/inventory-levels', ['data' => []])->assertUnauthorized();
        $this->patchJson('/api/inventory-levels/'.$level->id, ['data' => []])->assertUnauthorized();
        $this->getJson('/api/inventory-movements')->assertUnauthorized();
        $this->postJson('/api/inventory-movements', ['data' => []])->assertUnauthorized();
        $this->getJson('/api/inventory-movements/'.$movement->id)->assertUnauthorized();
    }

    public function test_collection_endpoints_exclude_records_from_other_households(): void
    {
        [$user] = $this->inventoryContext();
        $foreignMovement = InventoryMovement::factory()->create();
        $foreignLevel = InventoryLevel::factory()->create();

        $this->actingAs($user, 'web')->getJson('/api/inventory-levels')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson('/api/inventory-movements')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson('/api/inventory-movements/'.$foreignMovement->id)->assertNotFound();
        $this->patchJson('/api/inventory-levels/'.$foreignLevel->id, ['data' => ['alert_threshold' => 2]])->assertNotFound();
    }

    public function test_archived_items_and_locations_cannot_receive_movements(): void
    {
        [$user, $household, $item] = $this->inventoryContext();
        $location = $household->locations()->create(['name' => 'Pantry']);
        $archivedItem = Item::factory()->for($household)->create();
        $archivedItem->delete();
        $archivedLocation = $household->locations()->create(['name' => 'Shed']);
        $archivedLocation->delete();
        $this->actingAs($user, 'web');

        $this->postJson('/api/inventory-movements', ['data' => ['movement_type' => 'restock', 'item_id' => $archivedItem->id, 'location_id' => $location->id, 'quantity' => 1]])->assertUnprocessable();
        $this->postJson('/api/inventory-movements', ['data' => ['movement_type' => 'restock', 'item_id' => $item->id, 'location_id' => $archivedLocation->id, 'quantity' => 1]])->assertUnprocessable();
        $this->postJson('/api/inventory-movements', ['data' => ['movement_type' => 'transfer', 'item_id' => $item->id, 'source_location_id' => $location->id, 'destination_location_id' => $archivedLocation->id, 'quantity' => 1]])->assertUnprocessable();
        $this->postJson('/api/inventory-movements', ['data' => ['movement_type' => 'transfer', 'item_id' => $item->id, 'source_location_id' => $archivedLocation->id, 'destination_location_id' => $location->id, 'quantity' => 1]])->assertUnprocessable();
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_zero_stock_levels_at_archived_locations_are_hidden_from_current_reads(): void
    {
        [$user, $household, $item] = $this->inventoryContext();
        $location = $household->locations()->create(['name' => 'Unused Room']);
        $level = InventoryLevel::factory()->create(['item_id' => $item->id, 'location_id' => $location->id]);
        $this->actingAs($user, 'web');
        $this->deleteJson('/api/locations/'.$location->id)->assertNoContent();

        $this->getJson('/api/inventory-levels')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/inventory-levels/'.$level->id)->assertNotFound();
        $this->assertDatabaseHas('inventory_levels', ['id' => $level->id, 'quantity' => 0]);
    }

    public function test_alert_state_filters_return_only_matching_levels(): void
    {
        [$user, $household, $item] = $this->inventoryContext();
        $levels = [];
        foreach ([['empty', 0, 0], ['low', 2, 3], ['okay', 4, 3], ['unmonitored', 1, null]] as [$name, $quantity, $threshold]) {
            $location = $household->locations()->create(['name' => $name]);
            $levels[$name] = InventoryLevel::factory()->create([
                'item_id' => $item->id,
                'location_id' => $location->id,
                'quantity' => $quantity,
                'alert_threshold' => $threshold,
            ]);
        }
        $this->actingAs($user, 'web');

        foreach (['empty', 'low', 'okay', 'unmonitored'] as $status) {
            $this->getJson('/api/inventory-levels?filter[alert_status]='.$status)
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', (string) $levels[$status]->id)
                ->assertJsonPath('data.0.alert_status', $status);
        }
        $this->getJson('/api/inventory-levels?filter[alert_status]=triggered')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_transfer_history_filters_are_transfer_specific_and_do_not_duplicate_movements(): void
    {
        [$user, $household, $item] = $this->inventoryContext();
        $a = $household->locations()->create(['name' => 'A']);
        $b = $household->locations()->create(['name' => 'B']);
        $c = $household->locations()->create(['name' => 'C']);
        $d = $household->locations()->create(['name' => 'D']);
        $this->actingAs($user, 'web');
        $this->postJson('/api/inventory-movements', ['data' => ['movement_type' => 'restock', 'item_id' => $item->id, 'location_id' => $a->id, 'quantity' => 5]])->assertCreated();
        $this->postJson('/api/inventory-movements', ['data' => ['movement_type' => 'transfer', 'item_id' => $item->id, 'source_location_id' => $a->id, 'destination_location_id' => $b->id, 'quantity' => 2]])->assertCreated();
        $this->postJson('/api/inventory-movements', ['data' => ['movement_type' => 'transfer', 'item_id' => $item->id, 'source_location_id' => $c->id, 'destination_location_id' => $d->id, 'quantity' => 1]])->assertUnprocessable();
        $this->postJson('/api/inventory-movements', ['data' => ['movement_type' => 'correction', 'item_id' => $item->id, 'location_id' => $b->id, 'observed_quantity' => 0]])->assertCreated();

        $this->getJson('/api/inventory-movements?filter[from_location_id]='.$a->id)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/inventory-movements?filter[to_location_id]='.$b->id)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/inventory-movements?filter[location_id]='.$a->id)->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/inventory-movements?filter[location_id]='.$b->id)->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/inventory-movements?filter[from_location_id]='.$b->id)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_recorded_until_accepts_a_date_or_an_exact_offset_timestamp(): void
    {
        [$user, $household, $item] = $this->inventoryContext();
        $location = $household->locations()->create(['name' => 'Pantry']);
        $this->actingAs($user, 'web');
        $this->travelTo('2026-01-02T15:00:00+00:00');
        $this->postJson('/api/inventory-movements', ['data' => ['movement_type' => 'restock', 'item_id' => $item->id, 'location_id' => $location->id, 'quantity' => 1]])->assertCreated();
        $this->travelTo('2026-01-03T00:00:00+00:00');
        $this->postJson('/api/inventory-movements', ['data' => ['movement_type' => 'restock', 'item_id' => $item->id, 'location_id' => $location->id, 'quantity' => 1]])->assertCreated();

        $this->getJson('/api/inventory-movements?filter[recorded_until]=2026-01-02')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/inventory-movements?filter[recorded_until]=2026-01-02T10:00:00-05:00')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/inventory-movements?filter[recorded_from]=2026-01-02T10:00:00-05:00')->assertOk()->assertJsonCount(2, 'data');
        $this->travelBack();
    }

    public function test_duplicate_levels_and_forged_or_incompatible_movement_fields_are_rejected(): void
    {
        [$user, $household, $item] = $this->inventoryContext();
        $location = $household->locations()->create(['name' => 'Pantry']);
        $this->actingAs($user, 'web');
        $payload = ['data' => ['item_id' => $item->id, 'location_id' => $location->id]];
        $this->postJson('/api/inventory-levels', $payload)->assertCreated();
        $this->postJson('/api/inventory-levels', $payload)->assertConflict();

        $this->postJson('/api/inventory-movements', ['data' => ['movement_type' => 'restock', 'item_id' => $item->id, 'location_id' => $location->id, 'quantity' => 1, 'recorded_by' => $user->id]])->assertUnprocessable();
        $this->postJson('/api/inventory-movements', ['data' => ['movement_type' => 'restock', 'item_id' => $item->id, 'location_id' => $location->id, 'quantity' => 1, 'note' => null]])->assertUnprocessable();
        $this->postJson('/api/inventory-movements', ['data' => ['movement_type' => 'correction', 'item_id' => $item->id, 'location_id' => $location->id, 'observed_quantity' => 0, 'quantity' => 1]])->assertUnprocessable();
        $this->postJson('/api/inventory-movements', ['data' => ['movement_type' => 'correction', 'item_id' => $item->id, 'location_id' => $location->id, 'observed_quantity' => 0, 'note' => 'deferred annotation']])->assertUnprocessable();
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_invalid_level_includes_and_movement_mutation_methods_do_not_change_state(): void
    {
        [$user, $household, $item] = $this->inventoryContext();
        $location = $household->locations()->create(['name' => 'Pantry']);
        $this->actingAs($user, 'web');
        $this->postJson('/api/inventory-levels?include=secrets', ['data' => ['item_id' => $item->id, 'location_id' => $location->id]])->assertBadRequest();
        $this->postJson('/api/inventory-movements', ['data' => ['movement_type' => 'restock', 'item_id' => $item->id, 'location_id' => $location->id, 'quantity' => 2]])->assertCreated();
        $movement = InventoryMovement::query()->firstOrFail();
        $this->patchJson('/api/inventory-movements/'.$movement->id, ['data' => ['movement_type' => 'disposal']])->assertMethodNotAllowed();
        $this->deleteJson('/api/inventory-movements/'.$movement->id)->assertMethodNotAllowed();
        $this->patchJson('/api/inventory-levels/'.InventoryLevel::query()->firstOrFail()->id.'?include=secrets', ['data' => ['alert_threshold' => 1]])->assertBadRequest();
        $this->assertDatabaseCount('inventory_levels', 1);
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    public function test_missing_level_positive_correction_creates_unmonitored_stock(): void
    {
        [$user, $household, $item] = $this->inventoryContext();
        $location = $household->locations()->create(['name' => 'Pantry']);
        $this->actingAs($user, 'web');

        $this->postJson('/api/inventory-movements', ['data' => ['movement_type' => 'correction', 'item_id' => $item->id, 'location_id' => $location->id, 'observed_quantity' => 3]])
            ->assertCreated()
            ->assertJsonPath('data.movement_type', 'correction');

        $this->assertDatabaseHas('inventory_levels', ['item_id' => $item->id, 'location_id' => $location->id, 'quantity' => 3, 'alert_threshold' => null]);
        $this->assertDatabaseHas('inventory_movement_entries', ['quantity_delta' => 3, 'balance_after' => 3]);
    }

    public function test_inventory_policies_allow_household_members_and_deny_outsiders(): void
    {
        [$member, $household, $item] = $this->inventoryContext();
        $owner = User::factory()->create();
        Membership::factory()->owner()->for($household)->for($owner)->create();
        $outsider = User::factory()->create();
        $location = $household->locations()->create(['name' => 'Pantry']);
        $level = InventoryLevel::factory()->create(['item_id' => $item->id, 'location_id' => $location->id]);
        $movement = InventoryMovement::factory()->create(['household_id' => $household->id, 'item_id' => $item->id]);
        $levelPolicy = new InventoryLevelPolicy;
        $movementPolicy = new InventoryMovementPolicy;

        foreach ([$member, $owner] as $authorizedUser) {
            $this->assertTrue($levelPolicy->viewAny($authorizedUser));
            $this->assertTrue($levelPolicy->view($authorizedUser, $level));
            $this->assertTrue($levelPolicy->create($authorizedUser));
            $this->assertTrue($levelPolicy->update($authorizedUser, $level));
            $this->assertTrue($movementPolicy->viewAny($authorizedUser));
            $this->assertTrue($movementPolicy->view($authorizedUser, $movement));
            $this->assertTrue($movementPolicy->create($authorizedUser));
        }

        $this->assertFalse($levelPolicy->viewAny($outsider));
        $this->assertFalse($levelPolicy->view($outsider, $level));
        $this->assertFalse($levelPolicy->create($outsider));
        $this->assertFalse($levelPolicy->update($outsider, $level));
        $this->assertFalse($movementPolicy->viewAny($outsider));
        $this->assertFalse($movementPolicy->view($outsider, $movement));
        $this->assertFalse($movementPolicy->create($outsider));
    }

    /** @return array{User, Household, Item} */
    private function inventoryContext(): array
    {
        $user = User::factory()->create();
        $household = Household::factory()->create();
        Membership::factory()->for($household)->for($user)->create();
        $item = Item::factory()->for($household)->create();

        return [$user, $household, $item];
    }
}
