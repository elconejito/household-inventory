<?php

namespace Tests\Feature\Models;

use App\Models\Household;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class UserTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_user_household_relation_excludes_archived_memberships_but_history_remains_queryable(): void
    {
        $household = Household::factory()->create();
        $membership = Membership::factory()
            ->for($household)
            ->for(User::factory()->create())
            ->create();
        $user = $membership->user;
        $membership->delete();

        $this->assertCount(0, $user->households);
        $this->assertCount(1, $user->memberships()->withTrashed()->get());
    }
}
