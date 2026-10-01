<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Household;
use App\Models\Item;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_category_endpoints_return_401_without_authentication(): void
    {
        $this->getJson('/api/categories')->assertUnauthorized();
        $this->postJson('/api/categories', ['data' => ['name' => 'Paper']])->assertUnauthorized();
    }

    public function test_member_can_create_and_update_a_normalized_category(): void
    {
        [$user, $household] = $this->householdMember();

        $response = $this->actingAs($user, 'web')->postJson('/api/categories', [
            'data' => ['name' => "  Paper\t  Goods  "],
        ]);

        $category = Category::query()->where('household_id', $household->id)->firstOrFail();
        $response->assertCreated()
            ->assertExactJson(['data' => [
                'type' => 'categories',
                'id' => (string) $category->id,
                'name' => 'Paper Goods',
            ]])
            ->assertHeader('Location', route('categories.show', ['category' => $category->id]));
        $this->assertSame($household->id, $category->household_id);

        $this->patchJson('/api/categories/'.$category->id, [
            'data' => ['name' => "  Storage\t  Bins  "],
        ])->assertOk()
            ->assertExactJson(['data' => [
                'type' => 'categories',
                'id' => (string) $category->id,
                'name' => 'Storage Bins',
            ]]);
        $this->assertSame('Storage Bins', $category->fresh()->name);
    }

    public function test_category_requests_reject_data_id_and_type(): void
    {
        [$user] = $this->householdMember();

        $this->actingAs($user, 'web')->postJson('/api/categories', [
            'data' => ['id' => 'arbitrary', 'type' => 'items', 'name' => 'Paper'],
        ])->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'validation_failed');

        $this->assertDatabaseCount('categories', 0);
    }

    public function test_normalized_names_must_be_unique_including_archived_categories(): void
    {
        [$user, $household] = $this->householdMember();
        $archivedCategory = $household->categories()->create(['name' => 'Seasonal Storage']);
        $archivedCategory->delete();

        $this->actingAs($user, 'web')->postJson('/api/categories', [
            'data' => ['name' => "  Seasonal\t Storage  "],
        ])->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'validation_failed')
            ->assertJsonPath('errors.0.detail', 'The data.name has already been taken.');

        $this->assertDatabaseCount('categories', 1);
    }

    public function test_category_ids_from_another_household_return_404_for_read_and_write_routes(): void
    {
        [$user] = $this->householdMember();
        $foreignCategory = Category::factory()->create();
        $foreignCategory->delete();
        $this->actingAs($user, 'web');

        $this->getJson('/api/categories/'.$foreignCategory->id)->assertNotFound();
        $this->patchJson('/api/categories/'.$foreignCategory->id, ['data' => ['name' => 'Changed']])->assertNotFound();
        $this->deleteJson('/api/categories/'.$foreignCategory->id)->assertNotFound();
        $this->postJson('/api/categories/'.$foreignCategory->id.'/restore')->assertNotFound();
    }

    public function test_category_index_is_scoped_to_the_current_household(): void
    {
        [$user, $household] = $this->householdMember();
        $ownCategory = $household->categories()->create(['name' => 'Kitchen']);
        Category::factory()->create(['name' => 'Foreign']);

        $this->actingAs($user, 'web')->getJson('/api/categories')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $ownCategory->id);
    }

    public function test_index_filters_sorts_and_returns_exact_paginated_metadata_and_links(): void
    {
        [$user, $household] = $this->householdMember();
        foreach (range(1, 12) as $number) {
            $household->categories()->create(['name' => sprintf('Storage %02d', $number)]);
        }

        $response = $this->actingAs($user, 'web')->getJson('/api/categories');

        $response->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('data.0.name', 'Storage 01')
            ->assertExactJsonStructure([
                'data' => ['*' => ['type', 'id', 'name']],
                'meta' => ['current_page', 'from', 'last_page', 'per_page', 'to', 'total'],
                'links' => ['first', 'last', 'prev', 'next'],
            ])
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.from', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.to', 10)
            ->assertJsonPath('meta.total', 12);

        $filteredResponse = $this->getJson('/api/categories?filter[search]=Storage&sort=-name&per_page=25')
            ->assertOk()
            ->assertJsonCount(12, 'data')
            ->assertJsonPath('data.0.name', 'Storage 12')
            ->assertJsonPath('meta.from', 1)
            ->assertJsonPath('meta.per_page', 25)
            ->assertJsonPath('meta.to', 12)
            ->assertJsonPath('meta.total', 12);

        $this->assertStringContainsString('per_page=25', $filteredResponse->json('links.first'));
        $this->assertStringContainsString('filter%5Bsearch%5D=Storage', $filteredResponse->json('links.first'));

        $this->getJson('/api/categories?filter[name]=Storage%2001&sort=name')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Storage 01');
    }

    public function test_index_validates_page_sizes_and_trashed_filter_values(): void
    {
        [$user] = $this->householdMember();
        $this->actingAs($user, 'web');

        $this->getJson('/api/categories?per_page=20')->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'validation_failed');
        $this->getJson('/api/categories?filter[trashed]=sometimes')->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'validation_failed');
    }

    public function test_index_rejects_unknown_filters_sorts_and_nested_includes(): void
    {
        [$user] = $this->householdMember();
        $this->actingAs($user, 'web');

        $this->getJson('/api/categories?filter[unknown]=value')->assertBadRequest();
        $this->getJson('/api/categories?sort=email')->assertBadRequest();
        $this->getJson('/api/categories?include=items.categories')->assertBadRequest();
        $this->getJson('/api/categories?include=secrets')->assertBadRequest();
    }

    public function test_items_are_omitted_unless_explicitly_included_without_nested_categories(): void
    {
        [$user, $household] = $this->householdMember();
        $category = $household->categories()->create(['name' => 'Kitchen']);
        $item = Item::factory()->for($household)->create(['name' => 'Paper Towels']);
        $item->categories()->attach($category);
        $this->actingAs($user, 'web');

        $this->getJson('/api/categories/'.$category->id)
            ->assertOk()
            ->assertExactJson(['data' => [
                'type' => 'categories',
                'id' => (string) $category->id,
                'name' => 'Kitchen',
            ]]);

        $this->getJson('/api/categories/'.$category->id.'?include=')
            ->assertOk()
            ->assertJsonMissingPath('data.items');

        $this->getJson('/api/categories/'.$category->id.'?include=items')
            ->assertOk()
            ->assertJsonPath('data.items.0.type', 'items')
            ->assertJsonPath('data.items.0.id', (string) $item->id)
            ->assertJsonPath('data.items.0.name', 'Paper Towels')
            ->assertJsonMissingPath('data.items.0.categories');

        $this->getJson('/api/categories?include=items')
            ->assertOk()
            ->assertJsonPath('data.0.items.0.id', (string) $item->id)
            ->assertJsonMissingPath('data.0.items.0.categories');
    }

    public function test_archived_categories_are_discoverable_and_restored_with_assignments(): void
    {
        [$user, $household] = $this->householdMember();
        $category = $household->categories()->create(['name' => 'Seasonal']);
        $item = Item::factory()->for($household)->create();
        $item->categories()->attach($category);
        $this->actingAs($user, 'web');

        $this->deleteJson('/api/categories/'.$category->id)->assertNoContent();
        $this->assertSoftDeleted('categories', ['id' => $category->id]);
        $this->getJson('/api/categories/'.$category->id)->assertNotFound();
        $this->getJson('/api/categories')->assertJsonMissing(['id' => (string) $category->id]);
        $this->getJson('/api/categories?filter[trashed]=only')
            ->assertOk()
            ->assertJsonPath('data.0.id', (string) $category->id);

        $this->postJson('/api/categories/'.$category->id.'/restore?include=items')
            ->assertOk()
            ->assertJsonPath('data.id', (string) $category->id)
            ->assertJsonPath('data.items.0.id', (string) $item->id);
        $this->assertNotSoftDeleted('categories', ['id' => $category->id]);
        $this->assertDatabaseHas('category_item', [
            'category_id' => $category->id,
            'item_id' => $item->id,
        ]);
    }

    public function test_restoring_an_active_category_returns_409(): void
    {
        [$user, $household] = $this->householdMember();
        $category = $household->categories()->create(['name' => 'Kitchen']);

        $this->actingAs($user, 'web')->postJson('/api/categories/'.$category->id.'/restore')
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'conflict');
    }

    private function householdMember(): array
    {
        $user = User::factory()->create();
        $household = Household::factory()->create();
        Membership::factory()->for($household)->for($user)->create();

        return [$user, $household];
    }
}
