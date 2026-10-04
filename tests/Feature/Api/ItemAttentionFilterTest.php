<?php

namespace Tests\Feature\Api;

use App\Enums\InventoryAlertType;
use App\Models\Category;
use App\Models\Household;
use App\Models\InventoryAlert;
use App\Models\InventoryLevel;
use App\Models\Item;
use App\Models\Location;
use App\Models\Membership;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ItemAttentionFilterTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_stock_status_filters_items_across_active_household_locations_and_keeps_total_quantity_item_wide(): void
    {
        [$user, $household] = $this->householdMember();
        $positiveLocation = Location::factory()->for($household)->create();
        $selectedLocation = Location::factory()->for($household)->create();
        $archivedLocation = Location::factory()->for($household)->create();
        $archivedLocation->delete();

        $inStock = Item::factory()->for($household)->create(['name' => 'Tracked positive']);
        InventoryLevel::factory()->for($inStock)->for($positiveLocation)->create(['quantity' => 4]);
        InventoryLevel::factory()->for($inStock)->for($selectedLocation)->create(['quantity' => 0]);

        $trackedEmpty = Item::factory()->for($household)->create(['name' => 'Tracked empty']);
        InventoryLevel::factory()->for($trackedEmpty)->for($selectedLocation)->create(['quantity' => 0]);

        $untracked = Item::factory()->for($household)->create(['name' => 'Untracked']);

        $archivedLocationOnly = Item::factory()->for($household)->create(['name' => 'Archived location stock']);
        InventoryLevel::factory()->for($archivedLocationOnly)->for($archivedLocation)->create(['quantity' => 9]);

        $foreignHousehold = Household::factory()->create();
        $foreignLocation = Location::factory()->for($foreignHousehold)->create();
        $foreignItem = Item::factory()->for($foreignHousehold)->create();
        InventoryLevel::factory()->for($foreignItem)->for($foreignLocation)->create(['quantity' => 8]);

        $this->actingAs($user, 'web');

        $this->getJson('/api/items?filter[stock_status]=in_stock')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $inStock->getKey());

        $this->getJson('/api/items?filter[stock_status]=empty&include=inventory_levels.location')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->getJson('/api/items?filter[stock_status]=in_stock&filter[location_id]='.$selectedLocation->getKey().'&include=inventory_levels.location')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $inStock->getKey())
            ->assertJsonPath('data.0.total_quantity', 4)
            ->assertJsonCount(2, 'data.0.inventory_levels');
    }

    public function test_attention_status_filters_automatic_and_manual_attention_with_active_household_scope(): void
    {
        [$user, $household] = $this->householdMember();
        $location = Location::factory()->for($household)->create();
        $archivedLocation = Location::factory()->for($household)->create();
        $archivedLocation->delete();

        $empty = Item::factory()->for($household)->create(['name' => 'Threshold zero empty']);
        InventoryLevel::factory()->for($empty)->for($location)->create(['quantity' => 0, 'alert_threshold' => 0]);

        $low = Item::factory()->for($household)->create(['name' => 'Threshold equality']);
        InventoryLevel::factory()->for($low)->for($location)->create(['quantity' => 3, 'alert_threshold' => 3]);

        $manual = Item::factory()->for($household)->create(['name' => 'Manual only']);
        $this->createBuySoonAlert($manual, $household, $user);

        $resolvedOnly = Item::factory()->for($household)->create(['name' => 'Resolved reminder']);
        $this->createBuySoonAlert($resolvedOnly, $household, $user, now());

        $archivedLevelOnly = Item::factory()->for($household)->create(['name' => 'Archived location alert']);
        InventoryLevel::factory()->for($archivedLevelOnly)->for($archivedLocation)->create(['quantity' => 0, 'alert_threshold' => 0]);

        $ordinary = Item::factory()->for($household)->create(['name' => 'No attention']);
        $unmonitoredEmpty = Item::factory()->for($household)->create(['name' => 'Unmonitored at zero']);
        InventoryLevel::factory()->for($unmonitoredEmpty)->for($location)->create(['quantity' => 0, 'alert_threshold' => null]);

        $foreignHousehold = Household::factory()->create();
        $foreignUser = User::factory()->create();
        Membership::factory()->for($foreignHousehold)->for($foreignUser)->create();
        $foreignBuySoon = Item::factory()->for($household)->create(['name' => 'Foreign household alert']);
        $this->createBuySoonAlert($foreignBuySoon, $foreignHousehold, $foreignUser);

        $this->actingAs($user, 'web');

        $this->getJson('/api/items?filter[attention_status]=empty')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $empty->getKey());
        $this->getJson('/api/items?filter[attention_status]=low')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $low->getKey());
        $this->getJson('/api/items?filter[attention_status]=buy_soon')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $manual->getKey());
        $this->getJson('/api/items?filter[attention_status]=needs_attention')->assertOk()->assertJsonCount(3, 'data');
        $nonePage = $this->getJson('/api/items?filter[attention_status]=none')->assertOk()->assertJsonCount(5, 'data');

        $needsAttentionPage = $this->getJson('/api/items?filter[attention_status]=needs_attention&per_page=10')
            ->assertOk()
            ->assertJsonPath('meta.total', 3);
        $this->assertEqualsCanonicalizing(
            [(string) $empty->getKey(), (string) $low->getKey(), (string) $manual->getKey()],
            array_column($needsAttentionPage->json('data'), 'id'),
        );
        $this->assertContains((string) $ordinary->getKey(), array_column($this->getJson('/api/items?filter[attention_status]=none')->json('data'), 'id'));
        $this->assertContains((string) $unmonitoredEmpty->getKey(), array_column($nonePage->json('data'), 'id'));
        $this->assertContains((string) $archivedLevelOnly->getKey(), array_column($this->getJson('/api/items?filter[attention_status]=none')->json('data'), 'id'));
        $this->assertContains((string) $resolvedOnly->getKey(), array_column($this->getJson('/api/items?filter[attention_status]=none')->json('data'), 'id'));
        $this->assertNotContains((string) $foreignBuySoon->getKey(), array_column($needsAttentionPage->json('data'), 'id'));
    }

    public function test_stock_and_attention_filters_combine_with_existing_filters_and_pagination(): void
    {
        [$user, $household] = $this->householdMember();
        $category = Category::factory()->for($household)->create(['name' => 'Pantry']);
        $selectedLocation = Location::factory()->for($household)->create();
        $otherLocation = Location::factory()->for($household)->create();

        $first = Item::factory()->for($household)->create(['name' => 'Rice 1']);
        $first->categories()->attach($category);
        InventoryLevel::factory()->for($first)->for($selectedLocation)->create(['quantity' => 0]);
        InventoryLevel::factory()->for($first)->for($otherLocation)->create(['quantity' => 5, 'alert_threshold' => 5]);

        $second = Item::factory()->for($household)->create(['name' => 'Rice 2']);
        $second->categories()->attach($category);
        InventoryLevel::factory()->for($second)->for($selectedLocation)->create(['quantity' => 1]);
        InventoryLevel::factory()->for($second)->for($otherLocation)->create(['quantity' => 4]);

        $wrongSearch = Item::factory()->for($household)->create(['name' => 'Beans']);
        $wrongSearch->categories()->attach($category);
        InventoryLevel::factory()->for($wrongSearch)->for($selectedLocation)->create(['quantity' => 2, 'alert_threshold' => 1]);

        $archived = Item::factory()->for($household)->create(['name' => 'Rice archived']);
        $archived->categories()->attach($category);
        InventoryLevel::factory()->for($archived)->for($selectedLocation)->create(['quantity' => 1, 'alert_threshold' => 1]);
        $archived->delete();

        $this->actingAs($user, 'web');
        $response = $this->getJson('/api/items?filter[search]=Rice&filter[category_id]='.$category->getKey().'&filter[location_id]='.$selectedLocation->getKey().'&filter[stock_status]=in_stock&filter[attention_status]=low&per_page=10&include=inventory_levels.location')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $first->getKey())
            ->assertJsonPath('data.0.total_quantity', 5)
            ->assertJsonCount(2, 'data.0.inventory_levels')
            ->assertJsonPath('meta.total', 1);

        $this->assertSame((string) $first->getKey(), $response->json('data.0.id'));

        $archivedResult = $this->getJson('/api/items?filter[search]=Rice&filter[category_id]='.$category->getKey().'&filter[location_id]='.$selectedLocation->getKey().'&filter[stock_status]=in_stock&filter[attention_status]=low&filter[trashed]=only')
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->assertSame((string) $archived->getKey(), $archivedResult->json('data.0.id'));
    }

    public function test_needs_attention_is_grouped_with_other_filters_and_paginates_without_duplicate_items(): void
    {
        [$user, $household] = $this->householdMember();
        $category = Category::factory()->for($household)->create(['name' => 'Pantry']);
        $otherCategory = Category::factory()->for($household)->create(['name' => 'Laundry']);
        $location = Location::factory()->for($household)->create();
        $elsewhere = Location::factory()->for($household)->create();

        $multiAttention = Item::factory()->for($household)->create(['name' => 'Rice multi attention']);
        $multiAttention->categories()->attach($category);
        InventoryLevel::factory()->for($multiAttention)->for($location)->create(['quantity' => 0, 'alert_threshold' => 0]);
        InventoryLevel::factory()->for($multiAttention)->for($elsewhere)->create(['quantity' => 2, 'alert_threshold' => 2]);
        $this->createBuySoonAlert($multiAttention, $household, $user);

        $manualAttention = Item::factory()->for($household)->create(['name' => 'Rice manual attention']);
        $manualAttention->categories()->attach($category);
        InventoryLevel::factory()->for($manualAttention)->for($location)->create(['quantity' => 4]);
        $this->createBuySoonAlert($manualAttention, $household, $user);

        $matchingItems = [$multiAttention, $manualAttention];
        foreach (range(1, 11) as $number) {
            $item = Item::factory()->for($household)->create(['name' => 'Rice '.$number]);
            $item->categories()->attach($category);
            InventoryLevel::factory()->for($item)->for($location)->create(['quantity' => 0, 'alert_threshold' => 0]);
            $matchingItems[] = $item;
        }

        $differentCategory = Item::factory()->for($household)->create(['name' => 'Rice different category']);
        $differentCategory->categories()->attach($otherCategory);
        InventoryLevel::factory()->for($differentCategory)->for($location)->create(['quantity' => 0, 'alert_threshold' => 0]);

        $differentLocation = Item::factory()->for($household)->create(['name' => 'Rice different location']);
        $differentLocation->categories()->attach($category);
        InventoryLevel::factory()->for($differentLocation)->for($elsewhere)->create(['quantity' => 0, 'alert_threshold' => 0]);
        $this->createBuySoonAlert($differentLocation, $household, $user);

        $foreignHousehold = Household::factory()->create();
        $foreignUser = User::factory()->create();
        Membership::factory()->for($foreignHousehold)->for($foreignUser)->create();
        $foreignItem = Item::factory()->for($foreignHousehold)->create(['name' => 'Rice foreign attention']);
        $this->createBuySoonAlert($foreignItem, $foreignHousehold, $foreignUser);

        $this->actingAs($user, 'web');
        $url = '/api/items?filter[search]=Rice&filter[category_id]='.$category->getKey().'&filter[location_id]='.$location->getKey().'&filter[attention_status]=needs_attention&per_page=10';
        $firstPage = $this->getJson($url)
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 13)
            ->assertJsonPath('meta.last_page', 2);
        $secondPage = $this->getJson($url.'&page=2')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 13);

        $ids = array_merge(array_column($firstPage->json('data'), 'id'), array_column($secondPage->json('data'), 'id'));
        $this->assertCount(13, array_unique($ids));
        $this->assertContains((string) $multiAttention->getKey(), $ids);
        $this->assertContains((string) $manualAttention->getKey(), $ids);
        $this->assertNotContains((string) $differentCategory->getKey(), $ids);
        $this->assertNotContains((string) $differentLocation->getKey(), $ids);
        $this->assertNotContains((string) $foreignItem->getKey(), $ids);
    }

    public function test_stock_and_attention_filters_reject_arrays_and_commalists(): void
    {
        [$user] = $this->householdMember();
        $this->actingAs($user, 'web');

        foreach ([
            '/api/items?filter[stock_status][]=empty',
            '/api/items?filter[stock_status]=empty,in_stock',
            '/api/items?filter[stock_status]=in_stock,empty',
            '/api/items?filter[stock_status]=true',
            '/api/items?filter[attention_status][]=low',
            '/api/items?filter[attention_status]=low,buy_soon',
            '/api/items?filter[attention_status]=true',
        ] as $uri) {
            $this->getJson($uri)->assertUnprocessable()
                ->assertJsonPath('errors.0.code', 'validation_failed');
        }
    }

    private function householdMember(): array
    {
        $user = User::factory()->create();
        $household = Household::factory()->create();
        Membership::factory()->for($household)->for($user)->create();

        return [$user, $household];
    }

    private function createBuySoonAlert(Item $item, Household $household, User $creator, ?DateTimeInterface $resolvedAt = null): InventoryAlert
    {
        return InventoryAlert::factory()->for($item)->create([
            'household_id' => $household->getKey(),
            'alert_type' => InventoryAlertType::BuySoon,
            'created_by' => $creator->getKey(),
            'resolved_at' => $resolvedAt,
        ]);
    }
}
