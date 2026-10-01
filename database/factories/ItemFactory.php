<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'name' => fake()->unique()->words(2, true),
            'counting_unit' => fake()->randomElement(['box', 'piece', 'roll']),
            'description' => fake()->optional()->sentence(),
        ];
    }
}
