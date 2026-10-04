<?php

namespace Tests\Feature\Api;

use App\Enums\MembershipRole;
use App\Models\Category;
use App\Models\Household;
use App\Models\InventoryLevel;
use App\Models\Item;
use App\Models\Location;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CategoryItemAssignmentTest extends TestCase
{
    use LazilyRefreshDatabase;

    public static function householdRoles(): array
    {
        return [
            'owner' => [MembershipRole::Owner],
            'member' => [MembershipRole::Member],
        ];
    }

    public function test_guest_receives_401_for_category_item_assignment(): void
    {
        $category = Category::factory()->create();
        $item = Item::factory()->for($category->household)->create();

        $this->patchJson($this->assignmentUrl($category, $item), ['data' => ['assigned' => true]])
            ->assertUnauthorized();
    }

    #[DataProvider('householdRoles')]
    public function test_owner_and_member_can_idempotently_assign_or_unassign_only_the_requested_pair(MembershipRole $role): void
    {
        [$user, $household] = $this->householdMember($role);
        $category = $household->categories()->create(['name' => 'Kitchen']);
        $otherCategory = $household->categories()->create(['name' => 'Laundry']);
        $archivedCategory = $household->categories()->create(['name' => 'Archived']);
        $archivedCategory->delete();
        $item = Item::factory()->for($household)->create(['name' => 'Dish soap']);
        $location = Location::factory()->for($household)->create();
        $level = InventoryLevel::factory()->for($item)->for($location)->create(['quantity' => 6]);
        $item->categories()->attach([$otherCategory->getKey(), $archivedCategory->getKey()]);
        $this->actingAs($user, 'web');

        $this->patchJson($this->assignmentUrl($category, $item), ['data' => ['assigned' => true]])
            ->assertOk()
            ->assertExactJson(['data' => null]);
        $this->patchJson($this->assignmentUrl($category, $item), ['data' => ['assigned' => true]])
            ->assertOk()
            ->assertExactJson(['data' => null]);

        $this->assertDatabaseHas('category_item', ['category_id' => $category->getKey(), 'item_id' => $item->getKey()]);
        $this->assertDatabaseHas('category_item', ['category_id' => $otherCategory->getKey(), 'item_id' => $item->getKey()]);
        $this->assertDatabaseHas('category_item', ['category_id' => $archivedCategory->getKey(), 'item_id' => $item->getKey()]);

        $this->patchJson($this->assignmentUrl($category, $item), ['data' => ['assigned' => false]])
            ->assertOk()
            ->assertExactJson(['data' => null]);
        $this->patchJson($this->assignmentUrl($category, $item), ['data' => ['assigned' => false]])
            ->assertOk()
            ->assertExactJson(['data' => null]);

        $this->assertDatabaseMissing('category_item', ['category_id' => $category->getKey(), 'item_id' => $item->getKey()]);
        $this->assertDatabaseHas('category_item', ['category_id' => $otherCategory->getKey(), 'item_id' => $item->getKey()]);
        $this->assertDatabaseHas('category_item', ['category_id' => $archivedCategory->getKey(), 'item_id' => $item->getKey()]);
        $this->assertSame('Dish soap', $item->fresh()->name);
        $this->assertSame(6, $level->fresh()->quantity);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_foreign_or_archived_category_and_item_references_return_404(): void
    {
        [$user, $household] = $this->householdMember();
        $category = $household->categories()->create(['name' => 'Kitchen']);
        $item = Item::factory()->for($household)->create();
        $foreignHousehold = Household::factory()->create();
        $foreignCategory = $foreignHousehold->categories()->create(['name' => 'Foreign']);
        $foreignItem = Item::factory()->for($foreignHousehold)->create();
        $archivedCategory = $household->categories()->create(['name' => 'Archived category']);
        $archivedCategory->delete();
        $archivedItem = Item::factory()->for($household)->create();
        $archivedItem->delete();
        $this->actingAs($user, 'web');

        $this->patchJson($this->assignmentUrl($foreignCategory, $item), ['data' => ['assigned' => true]])->assertNotFound();
        $this->patchJson($this->assignmentUrl($category, $foreignItem), ['data' => ['assigned' => true]])->assertNotFound();
        $this->patchJson($this->assignmentUrl($archivedCategory, $item), ['data' => ['assigned' => true]])->assertNotFound();
        $this->patchJson($this->assignmentUrl($category, $archivedItem), ['data' => ['assigned' => true]])->assertNotFound();

        $this->assertDatabaseCount('category_item', 0);
    }

    public function test_assignment_rejects_missing_invalid_and_unexpected_data_without_writing(): void
    {
        [$user, $household] = $this->householdMember();
        $category = $household->categories()->create(['name' => 'Kitchen']);
        $item = Item::factory()->for($household)->create();
        $this->actingAs($user, 'web');

        foreach ([
            ['data' => []],
            ['data' => ['assigned' => 'yes']],
            ['data' => ['assigned' => true, 'extra' => 'value']],
            ['data' => ['assigned' => true, 'id' => $item->getKey()]],
            ['data' => ['assigned' => true, 'type' => 'items']],
        ] as $payload) {
            $this->patchJson($this->assignmentUrl($category, $item), $payload)
                ->assertUnprocessable()
                ->assertJsonPath('errors.0.code', 'validation_failed');
        }

        $this->assertDatabaseCount('category_item', 0);
    }

    public function test_assignment_returns_403_when_membership_is_revoked_after_household_lock(): void
    {
        [$user, $household, $membership] = $this->householdMemberWithMembership();
        $category = $household->categories()->create(['name' => 'Kitchen']);
        $item = Item::factory()->for($household)->create();
        $membershipRevokedDuringRequest = false;

        DB::listen(function (QueryExecuted $query) use ($household, $membership, &$membershipRevokedDuringRequest): void {
            if ($membershipRevokedDuringRequest
                || preg_match('/from\s+["`]?households["`]?\s+where\s+/i', $query->sql) !== 1
                || ! in_array($household->getKey(), array_map('intval', $query->bindings), true)) {
                return;
            }

            $membershipRevokedDuringRequest = true;
            $membership->delete();
        });

        $this->actingAs($user, 'web')->patchJson($this->assignmentUrl($category, $item), ['data' => ['assigned' => true]])
            ->assertForbidden();

        $this->assertTrue($membershipRevokedDuringRequest);
        $this->assertDatabaseMissing('category_item', ['category_id' => $category->getKey(), 'item_id' => $item->getKey()]);
        $this->assertFalse($membership->fresh()->trashed());
    }

    private function householdMember(MembershipRole $role = MembershipRole::Owner): array
    {
        [$user, $household] = $this->householdMemberWithMembership($role);

        return [$user, $household];
    }

    /** @return array{User, Household, Membership} */
    private function householdMemberWithMembership(MembershipRole $role = MembershipRole::Owner): array
    {
        $user = User::factory()->create();
        $household = Household::factory()->create();
        $membership = Membership::factory()->for($household)->for($user)->create(['role' => $role]);

        return [$user, $household, $membership];
    }

    private function assignmentUrl(Category $category, Item $item): string
    {
        return '/api/categories/'.$category->getKey().'/items/'.$item->getKey();
    }
}
