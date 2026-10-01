<?php

namespace Tests\Feature\Models;

use App\Models\Category;
use App\Models\Household;
use App\Models\Item;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_category_normalizes_its_name_and_preserves_item_assignments_when_archived(): void
    {
        $household = Household::factory()->create();
        $item = Item::factory()->for($household)->create();
        $category = $household->categories()->create(['name' => '  Paper   Goods  ']);
        $item->categories()->attach($category);
        $category->delete();

        $this->assertSame('Paper Goods', $category->name);
        $this->assertDatabaseHas('category_item', [
            'category_id' => $category->id,
            'item_id' => $item->id,
        ]);
        $this->assertCount(0, $item->fresh()->categories);

        $category->restore();

        $this->assertTrue($item->fresh()->categories->contains($category->id));
    }

    public function test_archived_category_name_remains_reserved_within_its_household(): void
    {
        $category = Category::factory()->create(['name' => 'Cleaning']);
        $category->delete();

        $this->expectException(QueryException::class);

        Category::factory()
            ->for($category->household)
            ->create(['name' => 'Cleaning']);
    }
}
