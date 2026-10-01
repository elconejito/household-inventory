<?php

namespace Tests\Feature\Models;

use App\Models\Household;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class HouseholdTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_household_relates_to_members_and_catalog_resources(): void
    {
        $household = Household::factory()->create();
        $user = User::factory()->create();
        $membership = $household->memberships()->create([
            'user_id' => $user->id,
            'role' => 'owner',
            'joined_at' => now(),
        ]);
        $item = $household->items()->create([
            'name' => 'Paper towels',
            'counting_unit' => 'roll',
        ]);
        $category = $household->categories()->create(['name' => 'Cleaning']);
        $location = $household->locations()->create(['name' => 'Pantry']);

        $this->assertTrue($household->memberships->contains($membership));
        $this->assertTrue($household->users->contains($user));
        $this->assertTrue($household->items->contains($item));
        $this->assertTrue($household->categories->contains($category));
        $this->assertTrue($household->locations->contains($location));
        $this->assertFalse($household->usesTimestamps());
    }
}
