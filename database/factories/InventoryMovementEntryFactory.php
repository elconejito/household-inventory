<?php

namespace Database\Factories;

use App\Models\InventoryMovement;
use App\Models\InventoryMovementEntry;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryMovementEntry>
 */
class InventoryMovementEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'inventory_movement_id' => InventoryMovement::factory(),
            'location_id' => fn (array $attributes): int => (int) Location::factory()->create([
                'household_id' => InventoryMovement::query()->findOrFail($attributes['inventory_movement_id'])->household_id,
            ])->getKey(),
            'quantity_delta' => 1,
            'balance_after' => 1,
        ];
    }
}
