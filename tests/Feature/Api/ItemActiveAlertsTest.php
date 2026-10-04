<?php

namespace Tests\Feature\Api;

use App\Enums\InventoryAlertType;
use App\Models\Household;
use App\Models\InventoryAlert;
use App\Models\InventoryLevel;
use App\Models\Item;
use App\Models\Location;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ItemActiveAlertsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_items_only_include_unresolved_alerts_when_explicitly_requested(): void
    {
        [$user, $household, $item] = $this->householdItem();
        $active = $this->alert($household, $item);
        $resolved = $this->alert($household, $item, now()->subMinute());
        $this->actingAs($user, 'web');

        $this->getJson('/api/items')
            ->assertOk()
            ->assertJsonMissingPath('data.0.active_alerts');
        $this->getJson('/api/items?include=active_alerts')
            ->assertOk()
            ->assertJsonCount(1, 'data.0.active_alerts')
            ->assertJsonPath('data.0.active_alerts.0.id', (string) $active->id)
            ->assertJsonMissing(['id' => (string) $resolved->id]);
        $this->getJson('/api/items/'.$item->id.'?include=active_alerts')
            ->assertOk()
            ->assertJsonCount(1, 'data.active_alerts')
            ->assertJsonPath('data.active_alerts.0.id', (string) $active->id);
    }

    public function test_item_active_alert_include_is_household_scoped_and_available_on_store_and_update(): void
    {
        [$user, $household, $item] = $this->householdItem();
        [, $foreignHousehold, $foreignItem] = $this->householdItem();
        $foreignAlert = $this->alert($foreignHousehold, $foreignItem);
        $this->actingAs($user, 'web');

        $this->getJson('/api/items?include=active_alerts')->assertOk()->assertJsonMissing(['id' => (string) $foreignAlert->id]);
        $created = $this->postJson('/api/items?include=active_alerts', ['data' => ['name' => 'Created', 'counting_unit' => 'unit']]);
        $created->assertCreated()->assertJsonPath('data.active_alerts', []);
        $this->patchJson('/api/items/'.$item->id.'?include=active_alerts', ['data' => ['description' => 'Updated']])
            ->assertOk()
            ->assertJsonPath('data.active_alerts', []);
    }

    public function test_inventory_level_nested_item_alert_include_is_opt_in_and_active_only(): void
    {
        [$user, $household, $item] = $this->householdItem();
        $location = Location::factory()->for($household)->create();
        $level = InventoryLevel::factory()->for($item)->for($location)->create();
        $active = $this->alert($household, $item);
        $this->alert($household, $item, now()->subMinute());
        $this->actingAs($user, 'web');

        $this->getJson('/api/inventory-levels?include=item&filter[item_id]='.$item->id)
            ->assertOk()
            ->assertJsonMissingPath('data.0.item.active_alerts');
        $this->getJson('/api/inventory-levels?include=item.active_alerts,location&filter[item_id]='.$item->id)
            ->assertOk()
            ->assertJsonPath('data.0.id', (string) $level->id)
            ->assertJsonPath('data.0.item.active_alerts.0.id', (string) $active->id)
            ->assertJsonPath('data.0.location.id', (string) $location->id)
            ->assertJsonCount(1, 'data.0.item.active_alerts');
        $this->getJson('/api/inventory-levels/'.$level->id.'?include=item.active_alerts,location')
            ->assertOk()
            ->assertJsonPath('data.item.active_alerts.0.id', (string) $active->id)
            ->assertJsonPath('data.location.id', (string) $location->id);
    }

    /** @return array{User, Household, Item} */
    private function householdItem(): array
    {
        $user = User::factory()->create();
        $household = Household::factory()->create();
        Membership::factory()->for($household)->for($user)->create();
        $item = Item::factory()->for($household)->create();

        return [$user, $household, $item];
    }

    private function alert(Household $household, Item $item, mixed $resolvedAt = null): InventoryAlert
    {
        return $household->inventoryAlerts()->create([
            'item_id' => $item->getKey(),
            'alert_type' => InventoryAlertType::BuySoon,
            'created_by' => $household->memberships()->firstOrFail()->user_id,
            'created_at' => now(),
            'resolved_at' => $resolvedAt,
        ]);
    }
}
