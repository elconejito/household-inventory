<?php

namespace Tests\Feature\Policies;

use App\Enums\MembershipRole;
use App\Models\Household;
use App\Models\Item;
use App\Models\ItemImage;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ItemImagePolicyTest extends TestCase
{
    use LazilyRefreshDatabase;

    public static function householdRoles(): array
    {
        return [
            'owner' => [MembershipRole::Owner],
            'member' => [MembershipRole::Member],
        ];
    }

    #[DataProvider('householdRoles')]
    public function test_household_members_can_manage_photos_for_active_items(MembershipRole $role): void
    {
        $user = User::factory()->create();
        $household = Household::factory()->create();
        Membership::factory()->for($household)->for($user)->create(['role' => $role]);
        $item = Item::factory()->for($household)->create();
        $image = ItemImage::factory()->for($item)->create();
        $deletedImage = ItemImage::factory()->for($item)->create();
        $deletedImage->delete();
        $gate = Gate::forUser($user);

        $this->assertTrue($gate->allows('viewAny', ItemImage::class));
        $this->assertTrue($gate->allows('create', [ItemImage::class, $item]));
        $this->assertTrue($gate->allows('view', $image));
        $this->assertTrue($gate->allows('update', $image));
        $this->assertTrue($gate->allows('delete', $image));
        $this->assertTrue($gate->allows('restore', $deletedImage));
    }

    public function test_members_of_other_households_cannot_view_or_change_photos(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->create();
        $image = ItemImage::factory()->for($item)->create();
        $gate = Gate::forUser($user);

        $this->assertFalse($gate->allows('create', [ItemImage::class, $item]));
        $this->assertFalse($gate->allows('view', $image));
        $this->assertFalse($gate->allows('update', $image));
        $this->assertFalse($gate->allows('delete', $image));
        $this->assertFalse($gate->allows('restore', $image));
    }

    public function test_photos_cannot_be_managed_after_their_item_is_archived(): void
    {
        $user = User::factory()->create();
        $household = Household::factory()->create();
        Membership::factory()->for($household)->for($user)->create();
        $item = Item::factory()->for($household)->create();
        $image = ItemImage::factory()->for($item)->create();
        $item->delete();
        $gate = Gate::forUser($user);

        $this->assertFalse($gate->allows('create', [ItemImage::class, $item]));
        $this->assertFalse($gate->allows('view', $image));
        $this->assertFalse($gate->allows('update', $image));
        $this->assertFalse($gate->allows('delete', $image));
        $this->assertFalse($gate->allows('restore', $image));
    }
}
