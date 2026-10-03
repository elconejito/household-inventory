<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\Membership;
use App\Models\Note;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * @extends Factory<Note>
 */
class NoteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'notable_type' => (new Item)->getMorphClass(),
            'notable_id' => Item::factory(),
            'body' => fake()->paragraph(),
            'created_by' => static function (array $attributes): ?int {
                $class = Relation::getMorphedModel((string) $attributes['notable_type']);
                if ($class === null) {
                    return null;
                }

                $householdId = $class::query()->whereKey($attributes['notable_id'])->value('household_id');

                return $householdId === null
                    ? null
                    : Membership::query()->where('household_id', $householdId)->whereNull('deleted_at')->value('user_id');
            },
        ];
    }
}
