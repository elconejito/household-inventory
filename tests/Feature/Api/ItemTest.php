<?php

namespace Tests\Feature\Api;

use App\Models\Household;
use App\Models\Item;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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
