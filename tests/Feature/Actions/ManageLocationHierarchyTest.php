<?php

namespace Tests\Feature\Actions;

use App\Actions\ManageLocationHierarchy;
use App\Models\Household;
use App\Models\Location;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ManageLocationHierarchyTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_root_names_are_unique_case_insensitively_including_archived_rows(): void
    {
        $household = Household::factory()->create();
        $hierarchy = app(ManageLocationHierarchy::class);
        $root = $hierarchy->create($household, ['name' => 'Basement']);
        $hierarchy->archive($household, $root);

        try {
            $hierarchy->create($household, ['name' => ' basement ']);
            $this->fail('An archived root location must continue to reserve its name.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('data.name', $exception->errors());
        }

        $this->assertSame(1, Location::withTrashed()->where('household_id', $household->id)->count());
    }

    public function test_same_sibling_name_is_allowed_under_different_parents(): void
    {
        $household = Household::factory()->create();
        $hierarchy = app(ManageLocationHierarchy::class);
        $basement = $hierarchy->create($household, ['name' => 'Basement']);
        $garage = $hierarchy->create($household, ['name' => 'Garage']);

        $basementShelf = $hierarchy->create($household, [
            'name' => 'Shelf',
            'parent_id' => $basement->id,
        ]);
        $garageShelf = $hierarchy->create($household, [
            'name' => 'Shelf',
            'parent_id' => $garage->id,
        ]);

        $this->assertSame($basement->id, $basementShelf->parent_id);
        $this->assertSame($garage->id, $garageShelf->parent_id);
        $this->assertNotSame($basementShelf->id, $garageShelf->id);
    }

    public function test_reparenting_changes_only_the_moved_location_and_rejects_descendant_cycles(): void
    {
        $household = Household::factory()->create();
        $hierarchy = app(ManageLocationHierarchy::class);
        $firstRoot = $hierarchy->create($household, ['name' => 'Basement']);
        $secondRoot = $hierarchy->create($household, ['name' => 'Garage']);
        $shelf = $hierarchy->create($household, [
            'name' => 'Shelf',
            'parent_id' => $firstRoot->id,
        ]);
        $drawer = $hierarchy->create($household, [
            'name' => 'Drawer',
            'parent_id' => $shelf->id,
        ]);

        $movedShelf = $hierarchy->update($household, $shelf, ['parent_id' => $secondRoot->id]);

        $this->assertSame($secondRoot->id, $movedShelf->parent_id);
        $this->assertSame($shelf->id, $drawer->fresh()->parent_id);

        try {
            $hierarchy->update($household, $secondRoot, ['parent_id' => $drawer->id]);
            $this->fail('A location cannot be moved beneath a descendant.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('data.parent_id', $exception->errors());
        }

        try {
            $hierarchy->update($household, $shelf, ['parent_id' => $shelf->id]);
            $this->fail('A location cannot be its own parent.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('data.parent_id', $exception->errors());
        }

        $this->assertSame($secondRoot->id, $shelf->fresh()->parent_id);
    }
}
