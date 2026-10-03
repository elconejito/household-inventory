<?php

namespace Tests\Feature;

use App\Enums\InventoryAlertType;
use App\Models\Household;
use App\Models\InventoryAlert;
use App\Models\InventoryLevel;
use App\Models\Item;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class InventoryAlertTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_unauthenticated_requests_return_401(): void
    {
        $this->getJson('/api/inventory-alerts')->assertUnauthorized();
        $this->postJson('/api/inventory-alerts', [])->assertUnauthorized();
        $alert = InventoryAlert::factory()->create();
        $this->getJson('/api/inventory-alerts/'.$alert->id)->assertUnauthorized();
        $this->postJson('/api/inventory-alerts/'.$alert->id.'/resolve')->assertUnauthorized();
    }

    public function test_authenticated_user_without_a_membership_receives_403(): void
    {
        $this->actingAs(User::factory()->create(), 'web');

        $this->getJson('/api/inventory-alerts')->assertForbidden();
        $this->postJson('/api/inventory-alerts', ['data' => ['item_id' => 1, 'alert_type' => 'buy_soon']])->assertForbidden();
    }

    public function test_member_can_create_alert_and_include_item_creator_and_resolver(): void
    {
        [$user, $household] = $this->householdMember();
        $item = $household->items()->create(['name' => 'Rice', 'counting_unit' => 'bag']);
        $location = $household->locations()->create(['name' => 'Pantry']);
        InventoryLevel::factory()->for($item)->for($location)->create(['quantity' => 6]);

        $response = $this->actingAs($user, 'web')->postJson('/api/inventory-alerts?include=item,created_by,resolved_by', [
            'data' => ['item_id' => $item->id, 'alert_type' => 'buy_soon'],
        ]);

        $alert = InventoryAlert::query()->firstOrFail();
        $response->assertCreated()
            ->assertJsonPath('data.type', 'inventory-alerts')
            ->assertJsonPath('data.alert_type', 'buy_soon')
            ->assertJsonPath('data.item.id', (string) $item->id)
            ->assertJsonPath('data.item.total_quantity', 6)
            ->assertJsonPath('data.created_by.id', (string) $user->id)
            ->assertJsonPath('data.resolved_by', null);
        $this->assertSame($household->id, $alert->household_id);
        $this->assertSame($user->id, $alert->created_by);
        $this->assertNull($alert->resolved_at);
    }

    public function test_duplicate_active_alert_returns_409_without_a_second_write(): void
    {
        [$user, $household] = $this->householdMember();
        $item = $household->items()->create(['name' => 'Oats', 'counting_unit' => 'bag']);
        $household->inventoryAlerts()->create([
            'item_id' => $item->id,
            'alert_type' => InventoryAlertType::BuySoon,
            'created_by' => $user->id,
            'created_at' => now(),
        ]);

        $this->actingAs($user, 'web')->postJson('/api/inventory-alerts', [
            'data' => ['item_id' => $item->id, 'alert_type' => 'buy_soon'],
        ])->assertConflict()->assertJsonPath('errors.0.code', 'active_inventory_alert_exists');

        $this->assertDatabaseCount('inventory_alerts', 1);
    }

    public function test_store_rejects_unknown_fields_and_invalid_includes_before_writing(): void
    {
        [$user, $household] = $this->householdMember();
        $item = $household->items()->create(['name' => 'Beans', 'counting_unit' => 'can']);
        $this->actingAs($user, 'web');

        $this->postJson('/api/inventory-alerts?include=not_a_relation', [
            'data' => ['item_id' => $item->id, 'alert_type' => 'buy_soon'],
        ])->assertBadRequest();
        $this->postJson('/api/inventory-alerts', [
            'data' => ['item_id' => $item->id, 'alert_type' => 'buy_soon', 'created_by' => 999],
        ])->assertUnprocessable();
        $this->postJson('/api/inventory-alerts', [
            'household_id' => $household->id,
            'data' => ['item_id' => $item->id, 'alert_type' => 'buy_soon'],
        ])->assertUnprocessable()->assertJsonPath('errors.0.detail', 'Unexpected request fields are not allowed.');
        $this->postJson('/api/inventory-alerts/'.$item->id.'/resolve?include=invalid')->assertBadRequest();

        $this->assertDatabaseCount('inventory_alerts', 0);
    }

    public function test_store_reports_required_invalid_enum_and_malformed_payload_fields(): void
    {
        [$user, $household] = $this->householdMember();
        $item = $household->items()->create(['name' => 'Validation', 'counting_unit' => 'unit']);
        $this->actingAs($user, 'web');

        $this->postJson('/api/inventory-alerts', ['data' => []])
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.detail', 'The data field is required.');
        $this->postJson('/api/inventory-alerts', ['data' => ['item_id' => $item->id]])
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.detail', 'The data.alert type field is required.');
        $this->postJson('/api/inventory-alerts', ['data' => ['item_id' => $item->id, 'alert_type' => 'urgent']])
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.detail', 'The selected data.alert type is invalid.');
        $this->postJson('/api/inventory-alerts', ['item_id' => $item->id, 'alert_type' => 'buy_soon'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.detail', 'The data field is required.');

        $this->assertDatabaseCount('inventory_alerts', 0);
    }

    public function test_create_rejects_archived_and_foreign_items_with_404(): void
    {
        [$user, $household] = $this->householdMember();
        $archivedItem = $household->items()->create(['name' => 'Old', 'counting_unit' => 'unit']);
        $archivedItem->delete();
        $foreignItem = Item::factory()->create();
        $this->actingAs($user, 'web');

        foreach ([$archivedItem, $foreignItem] as $item) {
            $this->postJson('/api/inventory-alerts', [
                'data' => ['item_id' => $item->id, 'alert_type' => 'buy_soon'],
            ])->assertNotFound();
        }

        $this->assertDatabaseCount('inventory_alerts', 0);
    }

    public function test_alert_routes_hide_foreign_household_records(): void
    {
        [$user] = $this->householdMember();
        $foreignAlert = InventoryAlert::factory()->create();
        $this->actingAs($user, 'web');

        $this->getJson('/api/inventory-alerts/'.$foreignAlert->id)->assertNotFound();
        $this->postJson('/api/inventory-alerts/'.$foreignAlert->id.'/resolve')->assertNotFound();
    }

    public function test_active_index_filters_alerts_and_hides_archived_items(): void
    {
        [$user, $household] = $this->householdMember();
        $activeItem = $household->items()->create(['name' => 'Active', 'counting_unit' => 'unit']);
        $archivedItem = $household->items()->create(['name' => 'Archived', 'counting_unit' => 'unit']);
        $household->inventoryAlerts()->create(['item_id' => $activeItem->id, 'alert_type' => InventoryAlertType::BuySoon, 'created_by' => $user->id, 'created_at' => now()]);
        $archivedItem->delete();
        $household->inventoryAlerts()->create(['item_id' => $archivedItem->id, 'alert_type' => InventoryAlertType::BuySoon, 'created_by' => $user->id, 'created_at' => now()]);

        $this->actingAs($user, 'web')->getJson('/api/inventory-alerts?filter[status]=active&per_page=25&include=item,created_by')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.per_page', 25)
            ->assertJsonPath('data.0.item.id', (string) $activeItem->id)
            ->assertJsonPath('data.0.created_by.id', (string) $user->id);
    }

    public function test_pagination_defaults_to_ten_and_accepts_supported_page_sizes(): void
    {
        [$user, $household] = $this->householdMember();
        $item = $household->items()->create(['name' => 'Pagination', 'counting_unit' => 'unit']);
        InventoryAlert::factory()->count(11)->for($item)->create();
        $this->actingAs($user, 'web');

        $this->getJson('/api/inventory-alerts')->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('meta.per_page', 10);
        foreach ([25, 50, 100] as $perPage) {
            $this->getJson('/api/inventory-alerts?per_page='.$perPage)->assertOk()->assertJsonPath('meta.per_page', $perPage);
        }
        foreach ([1, 101] as $perPage) {
            $this->getJson('/api/inventory-alerts?per_page='.$perPage)->assertUnprocessable();
        }
    }

    public function test_resolved_alert_history_is_visible_after_item_restore(): void
    {
        [$user, $household] = $this->householdMember();
        $item = $household->items()->create(['name' => 'Restored', 'counting_unit' => 'unit']);
        $this->actingAs($user, 'web');
        $this->postJson('/api/inventory-alerts', ['data' => ['item_id' => $item->id, 'alert_type' => 'buy_soon']])->assertCreated();
        $alert = InventoryAlert::query()->firstOrFail();
        $this->postJson('/api/inventory-alerts/'.$alert->id.'/resolve')->assertOk();
        $item->delete();

        $this->getJson('/api/inventory-alerts/'.$alert->id.'?include=item,created_by,resolved_by')
            ->assertOk()
            ->assertJsonPath('data.resolved_by.id', (string) $user->id)
            ->assertJsonPath('data.item.id', (string) $item->id);
        $this->postJson('/api/items/'.$item->id.'/restore')->assertOk();
        $this->assertModelExists($alert->fresh());
        $this->assertNotNull($alert->fresh()->resolved_at);
    }

    public function test_resolve_is_idempotent_and_a_later_alert_can_be_created(): void
    {
        [$user, $household] = $this->householdMember();
        $item = $household->items()->create(['name' => 'Tea', 'counting_unit' => 'box']);
        $this->actingAs($user, 'web');
        $this->postJson('/api/inventory-alerts', ['data' => ['item_id' => $item->id, 'alert_type' => 'buy_soon']])->assertCreated();
        $alert = InventoryAlert::query()->firstOrFail();

        $this->travelTo('2026-10-03 13:00:00 UTC');
        $this->postJson('/api/inventory-alerts/'.$alert->id.'/resolve')->assertOk();
        $firstResolvedAt = $alert->fresh()->resolved_at;
        $firstResolver = $alert->fresh()->resolved_by;
        $this->travelTo('2026-10-03 14:00:00 UTC');
        $this->postJson('/api/inventory-alerts/'.$alert->id.'/resolve')->assertOk();

        $this->assertSame($firstResolvedAt->toISOString(), $alert->fresh()->resolved_at->toISOString());
        $this->assertSame($firstResolver, $alert->fresh()->resolved_by);
        $this->postJson('/api/inventory-alerts', ['data' => ['item_id' => $item->id, 'alert_type' => 'buy_soon']])->assertCreated();
        $this->assertDatabaseCount('inventory_alerts', 2);
    }

    public function test_archive_is_blocked_until_active_alert_is_resolved_and_restock_does_not_resolve_it(): void
    {
        [$user, $household] = $this->householdMember();
        $item = $household->items()->create(['name' => 'Flour', 'counting_unit' => 'bag']);
        $alert = $household->inventoryAlerts()->create([
            'item_id' => $item->id,
            'alert_type' => InventoryAlertType::BuySoon,
            'created_by' => $user->id,
            'created_at' => now(),
        ]);
        $location = $household->locations()->create(['name' => 'Pantry']);
        $this->actingAs($user, 'web');

        $this->deleteJson('/api/items/'.$item->id)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'inventory_archive_blocked')
            ->assertJsonPath('errors.0.detail', 'The item cannot be archived while it has an unresolved manual alert.');
        $this->postJson('/api/inventory-movements', [
            'data' => ['movement_type' => 'restock', 'item_id' => $item->id, 'location_id' => $location->id, 'quantity' => 1],
        ])->assertCreated();
        $this->assertNull($alert->fresh()->resolved_at);
        $this->postJson('/api/inventory-alerts/'.$alert->id.'/resolve')->assertOk();
        $this->deleteJson('/api/items/'.$item->id)->assertConflict();
    }

    public function test_resolving_alert_allows_archive_when_item_has_no_stock_and_keeps_history(): void
    {
        [$user, $household] = $this->householdMember();
        $item = $household->items()->create(['name' => 'Archiveable', 'counting_unit' => 'unit']);
        $alert = $household->inventoryAlerts()->create([
            'item_id' => $item->id,
            'alert_type' => InventoryAlertType::BuySoon,
            'created_by' => $user->id,
            'created_at' => now(),
        ]);
        $this->actingAs($user, 'web');

        $this->postJson('/api/inventory-alerts/'.$alert->id.'/resolve')->assertOk();
        $this->deleteJson('/api/items/'.$item->id)->assertNoContent();

        $this->assertModelExists($alert->fresh());
        $this->getJson('/api/inventory-alerts/'.$alert->id)->assertOk();
    }

    private function householdMember(): array
    {
        $user = User::factory()->create();
        $household = Household::factory()->create();
        Membership::factory()->for($household)->for($user)->create();

        return [$user, $household];
    }
}
