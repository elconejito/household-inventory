<?php

namespace Database\Seeders;

use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\Seeder;

class HouseholdInvitationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $household = Household::factory()->create(['name' => 'Invitation Fixtures']);
        $owner = User::factory()->create();
        Membership::factory()->for($household)->for($owner)->owner()->create();

        HouseholdInvitation::factory()
            ->for($household)
            ->for($owner, 'inviter')
            ->expired()
            ->create();
        HouseholdInvitation::factory()
            ->for($household)
            ->for($owner, 'inviter')
            ->revoked()
            ->create();
    }
}
