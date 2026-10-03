<?php

namespace Database\Factories;

use App\Models\InventoryLevel;
use App\Models\Item;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryLevel>
 */
class InventoryLevelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'location_id' => fn (array $attributes): int => (int) Location::factory()->create([
                'household_id' => Item::query()->findOrFail($attributes['item_id'])->household_id,
            ])->getKey(),
            'quantity' => 0,
            'alert_threshold' => null,
        ];
    }
}
