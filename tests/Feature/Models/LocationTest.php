<?php

namespace Tests\Feature\Models;

use App\Models\Household;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class LocationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_location_normalizes_its_name_and_relates_to_its_parent_and_children(): void
    {
        $household = Household::factory()->create();
        $parent = $household->locations()->create(['name' => '  Laundry   Room ']);
        $child = $household->locations()->create(['name' => 'Upper Shelf']);
        $child->parent()->associate($parent);
        $child->save();

        $this->assertSame('Laundry Room', $parent->name);
        $this->assertSame($parent->id, $child->parent->id);
        $this->assertTrue($parent->children->contains($child));
        $this->assertFalse($parent->usesTimestamps());
    }

    public function test_location_unique_index_prevents_duplicate_non_root_sibling_names(): void
    {
        $household = Household::factory()->create();
        $parent = $household->locations()->create(['name' => 'Closet']);
        $firstChild = $household->locations()->create(['name' => 'Top Shelf']);
        $firstChild->parent()->associate($parent);
        $firstChild->save();

        $this->expectException(QueryException::class);

        $secondChild = $household->locations()->create(['name' => 'Top Shelf']);
        $secondChild->parent()->associate($parent);
        $secondChild->save();
    }
}
