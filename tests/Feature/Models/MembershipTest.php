<?php

namespace Tests\Feature\Models;

use App\Enums\MembershipRole;
use App\Models\Household;
use App\Models\Membership;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class MembershipTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_membership_casts_role_and_joined_at(): void
    {
        $membership = Membership::factory()->owner()->create();

        $this->assertSame(MembershipRole::Owner, $membership->role);
        $this->assertInstanceOf(CarbonImmutable::class, $membership->joined_at);
        $this->assertFalse($membership->usesTimestamps());
    }

    public function test_archived_membership_still_reserves_the_users_single_membership(): void
    {
        $membership = Membership::factory()->create();
        $membership->delete();
        $anotherHousehold = Household::factory()->create();

        $this->expectException(QueryException::class);

        Membership::factory()
            ->for($anotherHousehold, 'household')
            ->for($membership->user, 'user')
            ->create();
    }
}
