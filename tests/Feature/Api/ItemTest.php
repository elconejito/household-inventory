<?php

namespace Tests\Feature\Api;

use App\Models\Household;
use App\Models\InventoryLevel;
use App\Models\Item;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ItemTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_item_endpoints_return_401_without_authentication(): void
    {
        $this->getJson('/api/items')->assertUnauthorized();
        $this->postJson('/api/items', ['data' => []])->assertUnauthorized();
    }

    public function test_member_can_create_an_item_with_normalized_name_and_category(): void
    {
        [$user, $household] = $this->householdMember();
        $category = $household->categories()->create(['name' => 'Paper']);

        $response = $this->actingAs($user, 'web')->postJson('/api/items', [
            'data' => [
                'name' => "  Paper\t  Towels  ",
                'counting_unit' => 'roll',
                'description' => null,
                'category_ids' => [$category->id],
            ],
        ]);

        $item = Item::query()->where('household_id', $household->id)->firstOrFail();
        $response->assertCreated()
            ->assertExactJson(['data' => [
                'type' => 'items',
                'id' => (string) $item->id,
                'name' => 'Paper Towels',
                'counting_unit' => 'roll',
                'description' => null,
                'total_quantity' => 0,
                'counting_unit_plural' => 'rolls',
            ]])
            ->assertHeader('Location', route('items.show', ['item' => $item->id]));

        $this->assertSame($user->id, $item->household->memberships()->firstOrFail()->user_id);
        $this->assertTrue($item->categories->contains($category));
    }

    public function test_item_validation_rejects_unexpected_keys_and_normalized_duplicate_names(): void
    {
        [$user, $household] = $this->householdMember();
        Item::factory()->for($household)->create(['name' => 'Paper Towels']);

        $this->actingAs($user, 'web')->postJson('/api/items', [
            'data' => [
                'id' => 'arbitrary',
                'name' => ' Paper   Towels ',
                'counting_unit' => 'roll',
            ],
        ])->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'validation_failed');

        $this->assertDatabaseCount('items', 1);
    }

    public function test_category_ids_must_be_active_and_belong_to_the_current_household(): void
    {
        [$user, $household] = $this->householdMember();
        $foreignCategory = Household::factory()->create()->categories()->create(['name' => 'Foreign']);
        $archivedCategory = $household->categories()->create(['name' => 'Archived']);
        $archivedCategory->delete();

        foreach ([$foreignCategory, $archivedCategory] as $category) {
            $this->actingAs($user, 'web')->postJson('/api/items', [
                'data' => [
                    'name' => 'New Item '.$category->id,
                    'counting_unit' => 'piece',
                    'category_ids' => [$category->id],
                ],
            ])->assertUnprocessable()
                ->assertJsonPath('errors.0.code', 'validation_failed');
        }

        $this->assertDatabaseCount('items', 0);
    }

    public function test_item_index_combines_category_and_exact_location_filters_without_narrowing_total_quantity(): void
    {
        [$user, $household] = $this->householdMember();
        $category = $household->categories()->create(['name' => 'Kitchen']);
        $otherCategory = $household->categories()->create(['name' => 'Laundry']);
        $targetLocation = $household->locations()->create(['name' => 'Pantry']);
        $otherLocation = $household->locations()->create(['name' => 'Basement']);
        $matchingItem = Item::factory()->for($household)->create(['name' => 'Target Rice']);
        $matchingItem->categories()->attach($category);
        InventoryLevel::factory()->for($matchingItem)->for($targetLocation)->create(['quantity' => 3]);
        InventoryLevel::factory()->for($matchingItem)->for($otherLocation)->create(['quantity' => 4]);

        $sameCategoryElsewhere = Item::factory()->for($household)->create(['name' => 'Other Rice']);
        $sameCategoryElsewhere->categories()->attach($category);
        InventoryLevel::factory()->for($sameCategoryElsewhere)->for($otherLocation)->create(['quantity' => 2]);

        $sameLocationOtherCategory = Item::factory()->for($household)->create(['name' => 'Target Soap']);
        $sameLocationOtherCategory->categories()->attach($otherCategory);
        InventoryLevel::factory()->for($sameLocationOtherCategory)->for($targetLocation)->create(['quantity' => 1]);
        $this->actingAs($user, 'web');

        $this->getJson('/api/items?filter[category_id]='.$category->getKey().'&filter[location_id]='.$targetLocation->getKey().'&filter[name]=Target&sort=name')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $matchingItem->getKey())
            ->assertJsonPath('data.0.total_quantity', 7);

        $this->getJson('/api/items?filter[category_id]='.$category->getKey())
            ->assertOk()
            ->assertJsonCount(2, 'data');
        $this->getJson('/api/items?filter[location_id]='.$targetLocation->getKey())
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_item_index_filter_ids_must_be_active_and_belong_to_the_current_household(): void
    {
        [$user, $household] = $this->householdMember();
        $archivedCategory = $household->categories()->create(['name' => 'Archived Category']);
        $archivedCategory->delete();
        $archivedLocation = $household->locations()->create(['name' => 'Archived Location']);
        $archivedLocation->delete();
        $foreignHousehold = Household::factory()->create();
        $foreignCategory = $foreignHousehold->categories()->create(['name' => 'Foreign Category']);
        $foreignLocation = $foreignHousehold->locations()->create(['name' => 'Foreign Location']);
        $this->actingAs($user, 'web');

        foreach ([
            '/api/items?filter[category_id]=not-an-id',
            '/api/items?filter[category_id]='.$archivedCategory->getKey(),
            '/api/items?filter[category_id]='.$foreignCategory->getKey(),
            '/api/items?filter[location_id]=not-an-id',
            '/api/items?filter[location_id]='.$archivedLocation->getKey(),
            '/api/items?filter[location_id]='.$foreignLocation->getKey(),
        ] as $uri) {
            $this->getJson($uri)->assertUnprocessable();
        }
    }

    public function test_category_archived_after_validation_is_not_attached_when_creating_an_item(): void
    {
        [$user, $household] = $this->householdMember();
        $category = $household->categories()->create(['name' => 'Paper']);
        $archivedDuringValidation = false;

        DB::listen(function (QueryExecuted $query) use ($category, &$archivedDuringValidation): void {
            if (! $archivedDuringValidation
                && preg_match('/from [`"]categories[`"]?/i', $query->sql) === 1
                && in_array($category->getKey(), $query->bindings, true)) {
                $archivedDuringValidation = true;
                $category->delete();
            }
        });

        $this->actingAs($user, 'web')->postJson('/api/items', [
            'data' => [
                'name' => 'Paper Towels',
                'counting_unit' => 'roll',
                'category_ids' => [$category->getKey()],
            ],
        ])->assertUnprocessable();

        $this->assertTrue($archivedDuringValidation);
        $this->assertDatabaseCount('items', 0);
        $this->assertDatabaseMissing('category_item', ['category_id' => $category->getKey()]);
    }

    public function test_category_archived_after_validation_is_not_attached_when_updating_an_item(): void
    {
        [$user, $household] = $this->householdMember();
        $item = Item::factory()->for($household)->create(['description' => 'Original']);
        $existingCategory = $household->categories()->create(['name' => 'Current']);
        $newCategory = $household->categories()->create(['name' => 'Seasonal']);
        $item->categories()->attach($existingCategory);
        $archivedDuringValidation = false;

        DB::listen(function (QueryExecuted $query) use ($newCategory, &$archivedDuringValidation): void {
            if (! $archivedDuringValidation
                && preg_match('/from [`"]categories[`"]?/i', $query->sql) === 1
                && in_array($newCategory->getKey(), $query->bindings, true)) {
                $archivedDuringValidation = true;
                $newCategory->delete();
            }
        });

        $this->actingAs($user, 'web')->patchJson('/api/items/'.$item->getKey(), [
            'data' => [
                'description' => 'Changed',
                'category_ids' => [$newCategory->getKey()],
            ],
        ])->assertUnprocessable();

        $this->assertTrue($archivedDuringValidation);
        $this->assertSame('Original', $item->fresh()->description);
        $this->assertSame([$existingCategory->getKey()], $item->fresh()->categories->modelKeys());
    }

    public function test_patch_preserves_omitted_categories_and_syncs_provided_categories(): void
    {
        [$user, $household] = $this->householdMember();
        $item = Item::factory()->for($household)->create();
        $first = $household->categories()->create(['name' => 'First']);
        $second = $household->categories()->create(['name' => 'Second']);
        $item->categories()->attach($first);

        $this->actingAs($user, 'web')->patchJson('/api/items/'.$item->id, [
            'data' => ['description' => 'Updated description'],
        ])->assertOk();
        $this->assertSame([$first->id], $item->fresh()->categories->modelKeys());

        $this->patchJson('/api/items/'.$item->id, [
            'data' => ['category_ids' => [$second->id]],
        ])->assertOk();
        $this->assertSame([$second->id], $item->fresh()->categories->modelKeys());

        $this->patchJson('/api/items/'.$item->id, [
            'data' => ['category_ids' => []],
        ])->assertOk();
        $this->assertCount(0, $item->fresh()->categories);
    }

    public function test_index_filters_sorts_and_returns_default_pagination_metadata(): void
    {
        [$user, $household] = $this->householdMember();
        foreach (range(1, 12) as $number) {
            Item::factory()->for($household)->create([
                'name' => sprintf('Pantry %02d', $number),
            ]);
        }

        $response = $this->actingAs($user, 'web')->getJson('/api/items');

        $response->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.from', 1)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.to', 10)
            ->assertJsonPath('meta.total', 12)
            ->assertJsonPath('meta.last_page', 2);
        $this->assertNotNull($response->json('links.next'));

        $filteredResponse = $this->getJson('/api/items?filter[search]=Pantry&sort=-name&per_page=25')
            ->assertOk()
            ->assertJsonCount(12, 'data')
            ->assertJsonPath('meta.per_page', 25)
            ->assertJsonPath('data.0.name', 'Pantry 12');
        $this->assertStringContainsString('per_page=25', $filteredResponse->json('links.first'));
        $this->assertStringContainsString('filter%5Bsearch%5D=Pantry', $filteredResponse->json('links.first'));
    }

    public function test_index_rejects_unsupported_page_sizes(): void
    {
        [$user] = $this->householdMember();

        $this->actingAs($user, 'web')->getJson('/api/items?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'validation_failed');

        $this->getJson('/api/items?filter[trashed]=sometimes')
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'validation_failed');
    }

    public function test_categories_are_serialized_only_when_explicitly_included(): void
    {
        [$user, $household] = $this->householdMember();
        $item = Item::factory()->for($household)->create();
        $category = $household->categories()->create(['name' => 'Paper']);
        $item->categories()->attach($category);
        $this->actingAs($user, 'web');

        $this->getJson('/api/items/'.$item->id)
            ->assertOk()
            ->assertJsonMissingPath('data.categories');

        $this->getJson('/api/items/'.$item->id.'?include=')
            ->assertOk()
            ->assertJsonMissingPath('data.categories');

        $this->getJson('/api/items/'.$item->id.'?include=categories')
            ->assertOk()
            ->assertJsonPath('data.categories.0.type', 'categories')
            ->assertJsonPath('data.categories.0.id', (string) $category->id)
            ->assertJsonPath('data.categories.0.name', 'Paper');
    }

    public function test_index_rejects_unknown_filters_sorts_and_includes(): void
    {
        [$user] = $this->householdMember();
        $this->actingAs($user, 'web');

        $this->getJson('/api/items?include=secret')->assertBadRequest();
        $this->getJson('/api/items?sort=email')->assertBadRequest();
        $this->getJson('/api/items?filter[unknown]=value')->assertBadRequest();
    }

    public function test_invalid_include_does_not_create_an_item(): void
    {
        [$user] = $this->householdMember();

        $this->actingAs($user, 'web')->postJson('/api/items?include=secret', [
            'data' => [
                'name' => 'Paper Towels',
                'counting_unit' => 'roll',
            ],
        ])->assertBadRequest();

        $this->assertDatabaseCount('items', 0);
    }

    public function test_cross_household_item_ids_return_404_for_read_and_write_routes(): void
    {
        [$user] = $this->householdMember();
        $foreignItem = Item::factory()->create();
        $foreignItem->delete();
        $this->actingAs($user, 'web');

        $this->getJson('/api/items/'.$foreignItem->id)->assertNotFound();
        $this->patchJson('/api/items/'.$foreignItem->id, ['data' => ['name' => 'Changed']])->assertNotFound();
        $this->deleteJson('/api/items/'.$foreignItem->id)->assertNotFound();
        $this->postJson('/api/items/'.$foreignItem->id.'/restore')->assertNotFound();
    }

    public function test_restoring_an_active_item_returns_409(): void
    {
        [$user, $household] = $this->householdMember();
        $item = Item::factory()->for($household)->create();

        $this->actingAs($user, 'web')
            ->postJson('/api/items/'.$item->id.'/restore')
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'conflict');
    }

    public function test_archived_items_are_hidden_and_can_be_restored(): void
    {
        [$user, $household] = $this->householdMember();
        $item = Item::factory()->for($household)->create(['name' => 'Reserved Name']);
        $this->actingAs($user, 'web');

        $this->deleteJson('/api/items/'.$item->id)->assertNoContent();
        $this->assertSoftDeleted('items', ['id' => $item->id]);
        $this->getJson('/api/items/'.$item->id)->assertNotFound();
        $this->getJson('/api/items')->assertJsonMissing(['id' => (string) $item->id]);
        $this->getJson('/api/items?filter[trashed]=only')
            ->assertOk()
            ->assertJsonPath('data.0.id', (string) $item->id);

        $this->postJson('/api/items/'.$item->id.'/restore')
            ->assertOk()
            ->assertJsonPath('data.id', (string) $item->id);
        $this->assertNotSoftDeleted('items', ['id' => $item->id]);
    }

    private function householdMember(): array
    {
        $user = User::factory()->create();
        $household = Household::factory()->create();
        Membership::factory()->for($household)->for($user)->create();

        return [$user, $household];
    }
}
