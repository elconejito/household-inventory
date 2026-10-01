<?php

namespace Tests\Feature\Api;

use App\Actions\ManageLocationHierarchy;
use App\Models\Household;
use App\Models\Location;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class LocationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_location_endpoints_return_401_without_authentication(): void
    {
        $this->getJson('/api/locations')->assertUnauthorized();
        $this->postJson('/api/locations', ['data' => ['name' => 'Basement']])->assertUnauthorized();
    }

    public function test_member_can_create_root_and_child_locations_with_flat_scalar_fields(): void
    {
        [$user, $household] = $this->householdMember();
        $this->actingAs($user, 'web');

        $rootResponse = $this->postJson('/api/locations', [
            'data' => [
                'name' => "  Basement\t  Storage  ",
                'description' => null,
            ],
        ]);

        $root = Location::query()->where('household_id', $household->id)->firstOrFail();
        $rootResponse->assertCreated()
            ->assertExactJson(['data' => [
                'type' => 'locations',
                'id' => (string) $root->id,
                'name' => 'Basement Storage',
                'description' => null,
            ]])
            ->assertHeader('Location', route('locations.show', ['location' => $root->id]));

        $childResponse = $this->postJson('/api/locations', [
            'data' => [
                'name' => 'Metal Shelf',
                'description' => 'Along the north wall',
                'parent_id' => $root->id,
            ],
        ]);

        $childResponse->assertCreated()
            ->assertJsonPath('data.type', 'locations')
            ->assertJsonPath('data.name', 'Metal Shelf')
            ->assertJsonPath('data.description', 'Along the north wall')
            ->assertJsonMissingPath('data.parent_id');
        $this->assertSame($root->id, Location::query()->where('name', 'Metal Shelf')->value('parent_id'));
    }

    public function test_location_requests_reject_data_id_and_type(): void
    {
        [$user] = $this->householdMember();

        $this->actingAs($user, 'web')->postJson('/api/locations', [
            'data' => ['id' => 'arbitrary', 'type' => 'items', 'name' => 'Basement'],
        ])->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'validation_failed');

        $this->assertDatabaseCount('locations', 0);
    }

    public function test_parent_must_be_active_and_belong_to_the_current_household(): void
    {
        [$user, $household] = $this->householdMember();
        $foreignParent = Household::factory()->create()->locations()->create(['name' => 'Foreign']);
        $archivedParent = $household->locations()->create(['name' => 'Archived']);
        $archivedParent->delete();
        $this->actingAs($user, 'web');

        foreach ([$foreignParent, $archivedParent] as $parent) {
            $this->postJson('/api/locations', [
                'data' => [
                    'name' => 'Child '.$parent->id,
                    'parent_id' => $parent->id,
                ],
            ])->assertUnprocessable()
                ->assertJsonPath('errors.0.code', 'validation_failed')
                ->assertJsonPath('errors.0.source.pointer', '/data/parent_id');
        }

        $this->assertDatabaseCount('locations', 2);
    }

    public function test_root_and_sibling_names_are_reserved_even_when_archived(): void
    {
        [$user, $household] = $this->householdMember();
        $hierarchy = app(ManageLocationHierarchy::class);
        $parent = $hierarchy->create($household, ['name' => 'Basement']);
        $archivedRoot = $hierarchy->create($household, ['name' => 'Garage']);
        $archivedRoot->delete();
        $archivedChild = $hierarchy->create($household, ['name' => 'Storage', 'parent_id' => $parent->id]);
        $archivedChild->delete();
        $this->actingAs($user, 'web');

        $this->postJson('/api/locations', ['data' => ['name' => ' garage ']])
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'validation_failed');
        $this->postJson('/api/locations', [
            'data' => ['name' => 'Storage', 'parent_id' => $parent->id],
        ])->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'validation_failed');

        $this->assertSame(3, $household->locations()->withTrashed()->count());
    }

    public function test_same_name_is_allowed_under_different_parents_and_parent_can_have_children(): void
    {
        [$user, $household] = $this->householdMember();
        $this->actingAs($user, 'web');

        $basement = $this->postJson('/api/locations', ['data' => ['name' => 'Basement']])
            ->assertCreated()
            ->json('data.id');
        $garage = $this->postJson('/api/locations', ['data' => ['name' => 'Garage']])
            ->assertCreated()
            ->json('data.id');

        $firstShelf = $this->postJson('/api/locations', [
            'data' => ['name' => 'Shelf', 'parent_id' => (int) $basement],
        ])->assertCreated()->json('data.id');
        $secondShelf = $this->postJson('/api/locations', [
            'data' => ['name' => 'Shelf', 'parent_id' => (int) $garage],
        ])->assertCreated()->json('data.id');
        $drawer = $this->postJson('/api/locations', [
            'data' => ['name' => 'Drawer', 'parent_id' => (int) $firstShelf],
        ])->assertCreated()->json('data.id');

        $this->assertNotSame($firstShelf, $secondShelf);
        $this->assertDatabaseHas('locations', [
            'id' => $drawer,
            'household_id' => $household->id,
            'parent_id' => $firstShelf,
        ]);
    }

    public function test_patch_can_move_a_location_to_root_or_another_parent(): void
    {
        [$user, $household] = $this->householdMember();
        $hierarchy = app(ManageLocationHierarchy::class);
        $firstRoot = $hierarchy->create($household, ['name' => 'Basement']);
        $secondRoot = $hierarchy->create($household, ['name' => 'Garage']);
        $shelf = $hierarchy->create($household, ['name' => 'Shelf', 'parent_id' => $firstRoot->id]);
        $this->actingAs($user, 'web');

        $this->patchJson('/api/locations/'.$shelf->id, ['data' => ['description' => 'No move']])
            ->assertOk()
            ->assertJsonMissingPath('data.parent_id');
        $this->assertSame($firstRoot->id, $shelf->fresh()->parent_id);

        $this->patchJson('/api/locations/'.$shelf->id, ['data' => ['parent_id' => $secondRoot->id]])
            ->assertOk();
        $this->assertSame($secondRoot->id, $shelf->fresh()->parent_id);

        $this->patchJson('/api/locations/'.$shelf->id, ['data' => ['parent_id' => null]])
            ->assertOk();
        $this->assertNull($shelf->fresh()->parent_id);
    }

    public function test_patch_rejects_self_parent_and_descendant_cycles_with_422(): void
    {
        [$user, $household] = $this->householdMember();
        $hierarchy = app(ManageLocationHierarchy::class);
        $root = $hierarchy->create($household, ['name' => 'Basement']);
        $child = $hierarchy->create($household, ['name' => 'Storage', 'parent_id' => $root->id]);
        $grandchild = $hierarchy->create($household, ['name' => 'Shelf', 'parent_id' => $child->id]);
        $this->actingAs($user, 'web');

        $this->patchJson('/api/locations/'.$root->id, ['data' => ['parent_id' => $root->id]])
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.source.pointer', '/data/parent_id');
        $this->patchJson('/api/locations/'.$root->id, ['data' => ['parent_id' => $grandchild->id]])
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.source.pointer', '/data/parent_id');

        $this->assertNull($root->fresh()->parent_id);
    }

    public function test_index_filters_sorts_and_returns_paginated_metadata_with_query_string(): void
    {
        [$user, $household] = $this->householdMember();
        foreach (range(1, 12) as $number) {
            $household->locations()->create(['name' => sprintf('Storage %02d', $number)]);
        }

        $response = $this->actingAs($user, 'web')->getJson('/api/locations');
        $response->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertExactJsonStructure([
                'data' => ['*' => ['type', 'id', 'name', 'description']],
                'meta' => ['current_page', 'from', 'last_page', 'per_page', 'to', 'total'],
                'links' => ['first', 'last', 'prev', 'next'],
            ])
            ->assertJsonPath('data.0.name', 'Storage 01')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.from', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.to', 10)
            ->assertJsonPath('meta.total', 12);

        $filteredResponse = $this->getJson('/api/locations?filter[search]=Storage&sort=-name&per_page=25')
            ->assertOk()
            ->assertJsonCount(12, 'data')
            ->assertJsonPath('data.0.name', 'Storage 12')
            ->assertJsonPath('meta.from', 1)
            ->assertJsonPath('meta.per_page', 25)
            ->assertJsonPath('meta.to', 12)
            ->assertJsonPath('meta.total', 12);

        $this->assertStringContainsString('per_page=25', $filteredResponse->json('links.first'));
        $this->assertStringContainsString('filter%5Bsearch%5D=Storage', $filteredResponse->json('links.first'));
        $this->getJson('/api/locations?filter[name]=Storage%2001&sort=name')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Storage 01');
    }

    public function test_index_validates_page_sizes_and_trashed_filter_values(): void
    {
        [$user] = $this->householdMember();
        $this->actingAs($user, 'web');

        $this->getJson('/api/locations?per_page=20')->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'validation_failed');
        $this->getJson('/api/locations?filter[trashed]=sometimes')->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'validation_failed');
    }

    public function test_index_rejects_unknown_filters_sorts_and_nested_includes(): void
    {
        [$user] = $this->householdMember();
        $this->actingAs($user, 'web');

        $this->getJson('/api/locations?filter[unknown]=value')->assertBadRequest();
        $this->getJson('/api/locations?sort=email')->assertBadRequest();
        $this->getJson('/api/locations?include=children.parent')->assertBadRequest();
        $this->getJson('/api/locations?include=unknown')->assertBadRequest();
    }

    public function test_parent_and_children_are_omitted_unless_explicitly_included(): void
    {
        [$user, $household] = $this->householdMember();
        $hierarchy = app(ManageLocationHierarchy::class);
        $parent = $hierarchy->create($household, ['name' => 'Basement']);
        $child = $hierarchy->create($household, ['name' => 'Shelf', 'parent_id' => $parent->id]);
        $this->actingAs($user, 'web');

        $this->getJson('/api/locations/'.$parent->id)
            ->assertOk()
            ->assertExactJson(['data' => [
                'type' => 'locations',
                'id' => (string) $parent->id,
                'name' => 'Basement',
                'description' => null,
            ]]);
        $this->getJson('/api/locations/'.$parent->id.'?include=')
            ->assertOk()
            ->assertJsonMissingPath('data.children');

        $this->getJson('/api/locations/'.$parent->id.'?include=children')
            ->assertOk()
            ->assertJsonPath('data.children.0.type', 'locations')
            ->assertJsonPath('data.children.0.id', (string) $child->id)
            ->assertJsonPath('data.children.0.name', 'Shelf')
            ->assertJsonMissingPath('data.children.0.children');
        $this->getJson('/api/locations/'.$child->id.'?include=parent')
            ->assertOk()
            ->assertJsonPath('data.parent.id', (string) $parent->id)
            ->assertJsonPath('data.parent.name', 'Basement');
    }

    public function test_cross_household_location_ids_return_404_for_read_and_write_routes(): void
    {
        [$user] = $this->householdMember();
        $foreignHousehold = Household::factory()->create();
        $foreignLocation = app(ManageLocationHierarchy::class)->create($foreignHousehold, ['name' => 'Foreign']);
        app(ManageLocationHierarchy::class)->archive($foreignHousehold, $foreignLocation);
        $this->actingAs($user, 'web');

        $this->getJson('/api/locations/'.$foreignLocation->id)->assertNotFound();
        $this->patchJson('/api/locations/'.$foreignLocation->id, ['data' => ['name' => 'Changed']])->assertNotFound();
        $this->deleteJson('/api/locations/'.$foreignLocation->id)->assertNotFound();
        $this->postJson('/api/locations/'.$foreignLocation->id.'/restore')->assertNotFound();
    }

    public function test_index_never_returns_another_households_active_or_archived_locations(): void
    {
        [$user, $household] = $this->householdMember();
        $household->locations()->create(['name' => 'Shared Local Storage']);

        $foreignHousehold = Household::factory()->create();
        $foreignActive = $foreignHousehold->locations()->create(['name' => 'Shared Foreign Storage']);
        $foreignArchived = $foreignHousehold->locations()->create(['name' => 'Shared Foreign Archive']);
        $foreignArchived->delete();

        $this->actingAs($user, 'web');

        foreach ([
            '/api/locations',
            '/api/locations?filter[search]=Shared',
            '/api/locations?filter[trashed]=with',
        ] as $uri) {
            $ids = collect($this->getJson($uri)->assertOk()->json('data'))->pluck('id');

            $this->assertNotContains((string) $foreignActive->id, $ids);
            $this->assertNotContains((string) $foreignArchived->id, $ids);
        }
    }

    public function test_archive_conflicts_with_active_children_and_restore_requires_active_parent(): void
    {
        [$user, $household] = $this->householdMember();
        $hierarchy = app(ManageLocationHierarchy::class);
        $parent = $hierarchy->create($household, ['name' => 'Basement']);
        $child = $hierarchy->create($household, ['name' => 'Shelf', 'parent_id' => $parent->id]);
        $this->actingAs($user, 'web');

        $this->deleteJson('/api/locations/'.$parent->id)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'conflict');
        $this->deleteJson('/api/locations/'.$child->id)->assertNoContent();
        $this->deleteJson('/api/locations/'.$parent->id)->assertNoContent();
        $this->postJson('/api/locations/'.$child->id.'/restore')
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'conflict');
        $this->postJson('/api/locations/'.$parent->id.'/restore')->assertOk();
        $this->postJson('/api/locations/'.$child->id.'/restore')->assertOk();
        $this->assertNotSoftDeleted('locations', ['id' => $parent->id]);
        $this->assertNotSoftDeleted('locations', ['id' => $child->id]);
    }

    public function test_restoring_an_active_location_returns_409(): void
    {
        [$user, $household] = $this->householdMember();
        $location = app(ManageLocationHierarchy::class)->create($household, ['name' => 'Basement']);

        $this->actingAs($user, 'web')->postJson('/api/locations/'.$location->id.'/restore')
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
