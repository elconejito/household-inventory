<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\InventoryMovement;
use App\Models\Item;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class InventoryMovementRecorderTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_unauthenticated_request_to_movement_recorders_returns_401(): void
    {
        $this->getJson('/api/inventory-movement-recorders')->assertUnauthorized();
    }

    public function test_owner_and_member_can_list_distinct_current_household_recorders_without_email(): void
    {
        $household = Household::factory()->create();
        $owner = User::factory()->create(['name' => 'Avery Owner']);
        $member = User::factory()->create(['name' => 'Morgan Member']);
        $formerRecorder = User::factory()->create(['name' => 'Taylor Former']);
        $noHistoryMember = User::factory()->create(['name' => 'Casey No History']);
        Membership::factory()->owner()->for($household)->for($owner)->create();
        Membership::factory()->for($household)->for($member)->create();
        $formerMembership = Membership::factory()->for($household)->for($formerRecorder)->create();
        Membership::factory()->for($household)->for($noHistoryMember)->create();
        $formerMembership->delete();
        $item = Item::factory()->for($household)->create();

        $this->recordMovement($household, $item, $member, '2026-10-01 10:00:00');
        $this->recordMovement($household, $item, $owner, '2026-10-01 11:00:00');
        $this->recordMovement($household, $item, $owner, '2026-10-01 12:00:00');
        $this->recordMovement($household, $item, $formerRecorder, '2026-10-01 13:00:00');

        $foreignHousehold = Household::factory()->create();
        $foreignRecorder = User::factory()->create(['name' => 'Foreign Recorder']);
        Membership::factory()->for($foreignHousehold)->for($foreignRecorder)->create();
        $foreignItem = Item::factory()->for($foreignHousehold)->create();
        $this->recordMovement($foreignHousehold, $foreignItem, $foreignRecorder, '2026-10-01 14:00:00');

        $response = $this->actingAs($member, 'web')
            ->getJson('/api/inventory-movement-recorders')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.id', (string) $owner->getKey())
            ->assertJsonPath('data.0.name', 'Avery Owner')
            ->assertJsonPath('data.1.id', (string) $member->getKey())
            ->assertJsonPath('data.2.id', (string) $formerRecorder->getKey())
            ->assertJsonMissing(['id' => (string) $noHistoryMember->getKey()])
            ->assertJsonMissing(['id' => (string) $foreignRecorder->getKey()])
            ->assertExactJsonStructure([
                'data' => ['*' => ['type', 'id', 'name']],
                'meta' => ['current_page', 'from', 'last_page', 'per_page', 'to', 'total'],
                'links' => ['first', 'last', 'prev', 'next'],
            ]);

        $this->app['auth']->forgetGuards();
        $this->actingAs($owner, 'web')
            ->getJson('/api/inventory-movement-recorders')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_movement_recorders_are_paginated_by_name_then_id_and_reject_invalid_page_sizes(): void
    {
        $household = Household::factory()->create();
        $requester = User::factory()->create();
        Membership::factory()->owner()->for($household)->for($requester)->create();
        $item = Item::factory()->for($household)->create();
        $recorders = [];

        for ($index = 0; $index < 26; $index++) {
            $name = $index < 2 ? 'Alpha Recorder' : sprintf('Recorder %02d', $index);
            $recorder = User::factory()->create(['name' => $name]);
            Membership::factory()->for($household)->for($recorder)->create();
            $this->recordMovement($household, $item, $recorder, '2026-10-01 10:00:00');
            $recorders[] = $recorder;
        }
        $expectedAlphaIds = collect(array_slice($recorders, 0, 2))
            ->pluck('id')
            ->map(static fn (int $id): string => (string) $id)
            ->sort()
            ->values()
            ->all();
        $this->actingAs($requester, 'web');

        $this->getJson('/api/inventory-movement-recorders')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('data.0.name', 'Alpha Recorder')
            ->assertJsonPath('data.0.id', $expectedAlphaIds[0])
            ->assertJsonPath('data.1.id', $expectedAlphaIds[1])
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 26);

        $this->getJson('/api/inventory-movement-recorders?per_page=25')
            ->assertOk()
            ->assertJsonCount(25, 'data')
            ->assertJsonPath('meta.per_page', 25)
            ->assertJsonPath('meta.last_page', 2);

        $this->getJson('/api/inventory-movement-recorders?per_page=50')
            ->assertOk()
            ->assertJsonCount(26, 'data')
            ->assertJsonPath('meta.per_page', 50);

        $this->getJson('/api/inventory-movement-recorders?per_page=100')
            ->assertOk()
            ->assertJsonCount(26, 'data')
            ->assertJsonPath('meta.per_page', 100);

        $this->getJson('/api/inventory-movement-recorders?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.source.pointer', '/data/per_page');
        $this->getJson('/api/inventory-movement-recorders?per_page=101')
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.source.pointer', '/data/per_page');
    }

    public function test_recorder_endpoint_rejects_unsupported_filters_sorts_and_includes(): void
    {
        [$requester, $household, $item] = $this->householdContext();
        $this->recordMovement($household, $item, $requester, '2026-10-01 10:00:00');
        $this->actingAs($requester, 'web');

        $this->getJson('/api/inventory-movement-recorders?filter[email]=private@example.test')->assertBadRequest();
        $this->getJson('/api/inventory-movement-recorders?sort=email')->assertBadRequest();
        $this->getJson('/api/inventory-movement-recorders?include=membership')->assertBadRequest();
    }

    public function test_movement_index_combines_item_recorder_and_date_filters_and_hides_foreign_recorder_history(): void
    {
        [$requester, $household, $item] = $this->householdContext();
        $recorder = User::factory()->create();
        $otherRecorder = User::factory()->create();
        Membership::factory()->for($household)->for($recorder)->create();
        Membership::factory()->for($household)->for($otherRecorder)->create();
        $oldMovement = $this->recordMovement($household, $item, $recorder, '2026-09-30 23:59:59');
        $firstTiedMovement = $this->recordMovement($household, $item, $recorder, '2026-10-01 12:00:00');
        $secondTiedMovement = $this->recordMovement($household, $item, $recorder, '2026-10-01 12:00:00');
        $this->recordMovement($household, $item, $recorder, '2026-10-02 00:00:00');
        $this->recordMovement($household, $item, $otherRecorder, '2026-10-01 12:00:00');
        $otherItem = Item::factory()->for($household)->create();
        $this->recordMovement($household, $otherItem, $recorder, '2026-10-01 12:00:00');

        $foreignHousehold = Household::factory()->create();
        $foreignRecorder = User::factory()->create();
        Membership::factory()->for($foreignHousehold)->for($foreignRecorder)->create();
        $foreignItem = Item::factory()->for($foreignHousehold)->create();
        $this->recordMovement($foreignHousehold, $foreignItem, $foreignRecorder, '2026-10-01 12:00:00');

        $this->actingAs($requester, 'web');
        $this->getJson('/api/inventory-movements?filter[item_id]='.$item->getKey()
            .'&filter[recorded_by]='.$recorder->getKey()
            .'&filter[recorded_from]=2026-10-01T00:00:00Z'
            .'&filter[recorded_until]=2026-10-01T23:59:59Z')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', (string) $secondTiedMovement->getKey())
            ->assertJsonPath('data.1.id', (string) $firstTiedMovement->getKey())
            ->assertJsonMissing(['id' => (string) $oldMovement->getKey()]);

        $this->getJson('/api/inventory-movements?filter[recorded_by]='.$foreignRecorder->getKey())
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /** @return array{User, Household, Item} */
    private function householdContext(): array
    {
        $user = User::factory()->create();
        $household = Household::factory()->create();
        Membership::factory()->owner()->for($household)->for($user)->create();
        $item = Item::factory()->for($household)->create();

        return [$user, $household, $item];
    }

    private function recordMovement(Household $household, Item $item, User $recorder, string $recordedAt): InventoryMovement
    {
        return InventoryMovement::factory()->create([
            'household_id' => $household->getKey(),
            'item_id' => $item->getKey(),
            'recorded_by' => $recorder->getKey(),
            'recorded_at' => $recordedAt,
        ]);
    }
}
