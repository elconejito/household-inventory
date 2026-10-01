<?php

namespace Tests\Feature\Models;

use App\Models\Household;
use App\Models\Item;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ItemTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_item_name_is_trimmed_and_internal_whitespace_is_collapsed(): void
    {
        $item = Household::factory()->create()->items()->create([
            'name' => "  Kitchen\t  Paper   Towels  ",
            'counting_unit' => 'roll',
        ]);

        $this->assertSame('Kitchen Paper Towels', $item->name);
        $this->assertFalse($item->usesTimestamps());
    }

    public function test_archived_item_name_remains_reserved_within_its_household(): void
    {
        $item = Item::factory()->create(['name' => 'Paper Towels']);
        $item->delete();

        $this->expectException(QueryException::class);

        Item::factory()
            ->for($item->household)
            ->create(['name' => 'Paper Towels']);
    }
}
