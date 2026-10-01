<?php

namespace Database\Factories;

use App\Enums\MembershipRole;
use App\Models\Household;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Membership>
 */
class MembershipFactory extends Factory
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
            'user_id' => User::factory(),
            'role' => MembershipRole::Member->value,
            'joined_at' => now(),
        ];
    }

    public function owner(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => MembershipRole::Owner->value,
        ]);
    }
}
