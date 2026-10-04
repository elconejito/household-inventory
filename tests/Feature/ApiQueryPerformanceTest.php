<?php

namespace Tests\Feature;

use App\Actions\CreateInventoryMovement;
use App\Models\Category;
use App\Models\Household;
use App\Models\InventoryAlert;
use App\Models\Item;
use App\Models\ItemImage;
use App\Models\Location;
use App\Models\Membership;
use App\Models\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApiQueryPerformanceTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array<string, array{string}> */
    public static function collectionEndpoints(): array
    {
        return [
            'item graph' => ['/api/items?include=categories,inventory_levels.location,notes.created_by,images.uploaded_by,active_alerts'],
            'item graph with stock and attention filters' => ['/api/items?filter[stock_status]=in_stock&filter[attention_status]=needs_attention&include=categories,inventory_levels.location,notes.created_by,images.uploaded_by,active_alerts'],
            'category graph' => ['/api/categories?include=items,notes.created_by'],
            'location hierarchy' => ['/api/locations?include=parent,children,notes.created_by'],
            'dashboard conditions' => ['/api/inventory-levels?include=item,location&filter[alert_status]=low'],
            'location active flags' => ['/api/inventory-levels?include=item.active_alerts,location'],
            'activity graph' => ['/api/inventory-movements?include=item,entries.location,recorded_by,notes.created_by'],
            'manual alerts' => ['/api/inventory-alerts?include=item,created_by,notes.created_by&filter[status]=active'],
        ];
    }

    #[DataProvider('collectionEndpoints')]
    public function test_included_collection_query_count_does_not_grow_per_record(string $endpoint): void
    {
        $user = User::factory()->create();
        $household = Household::factory()->create();
        Membership::factory()->for($household)->for($user)->create();
        $parent = Location::factory()->for($household)->create();
        $this->createSupply($household, $user, $parent);
        $this->actingAs($user, 'web');

        $isLocationList = str_starts_with($endpoint, '/api/locations?');
        $smallCount = $this->selectQueryCount($endpoint.'&per_page=25', $isLocationList ? 2 : 1);
        for ($index = 0; $index < 19; $index++) {
            $this->createSupply($household, $user, $parent);
        }
        $largeCount = $this->selectQueryCount($endpoint.'&per_page=25', $isLocationList ? 21 : 20);

        $this->assertGreaterThan(0, $smallCount);
        $this->assertSame($smallCount, $largeCount, 'Explicit includes must be eagerly loaded, not queried separately for every row.');
    }

    private function createSupply(Household $household, User $user, Location $parent): void
    {
        $item = Item::factory()->for($household)->create();
        $category = Category::factory()->for($household)->create();
        $item->categories()->attach($category);
        $location = Location::factory()->for($household)->create(['parent_id' => $parent->getKey()]);
        $movement = app(CreateInventoryMovement::class)->create($household, $user, [
            'movement_type' => 'restock', 'item_id' => $item->getKey(),
            'location_id' => $location->getKey(), 'quantity' => 1,
        ]);
        $item->inventoryLevels()->firstOrFail()->update(['alert_threshold' => 2]);
        $alert = InventoryAlert::factory()->for($item)->create([
            'household_id' => $household->getKey(), 'created_by' => $user->getKey(),
        ]);
        ItemImage::factory()->for($item)->create(['is_primary' => true, 'uploaded_by' => $user->getKey()]);
        foreach ([$item, $category, $location, $movement, $alert] as $notable) {
            Note::factory()->for($notable, 'notable')->create(['created_by' => $user->getKey()]);
        }
    }

    private function selectQueryCount(string $endpoint, int $recordCount): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $response = $this->getJson($endpoint);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $response->assertOk()->assertJsonCount($recordCount, 'data');

        return count(array_filter($queries, static fn (array $query): bool => str_starts_with(strtolower(ltrim($query['query'])), 'select')));
    }
}
