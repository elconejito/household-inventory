<?php

namespace Database\Factories;

use App\Enums\InventoryAlertType;
use App\Models\Household;
use App\Models\InventoryAlert;
use App\Models\Item;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryAlert>
 */
class InventoryAlertFactory extends Factory
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
            'household_id' => fn (array $attributes): int => (int) Item::query()->findOrFail($attributes['item_id'])->household_id,
            'alert_type' => InventoryAlertType::BuySoon,
            'created_by' => fn (array $attributes): int => tap(User::factory()->create(), function (User $user) use ($attributes): void {
                Membership::factory()->for(Household::query()->findOrFail($attributes['household_id']))->for($user)->create();
            })->getKey(),
            'created_at' => now(),
            'resolved_at' => null,
            'resolved_by' => null,
        ];
    }
}
