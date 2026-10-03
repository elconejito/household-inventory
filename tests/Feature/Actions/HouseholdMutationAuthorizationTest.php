<?php

namespace Tests\Feature\Actions;

use App\Actions\ArchiveItem;
use App\Actions\CreateInventoryAlert;
use App\Actions\CreateInventoryMovement;
use App\Actions\ManageInventoryLevel;
use App\Actions\ManageItemImage;
use App\Actions\ResolveInventoryAlert;
use App\Actions\UploadItemImage;
use App\Enums\InventoryAlertType;
use App\Models\Household;
use App\Models\InventoryAlert;
use App\Models\InventoryLevel;
use App\Models\Item;
use App\Models\ItemImage;
use App\Models\Location;
use App\Models\Membership;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class HouseholdMutationAuthorizationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_locked_inventory_and_image_writes_reject_a_membership_removed_after_controller_authorization(): void
    {
        Storage::fake('inventory-images');
        $user = User::factory()->create();
        $household = Household::factory()->create();
        $membership = Membership::factory()->for($household)->for($user)->create();
        $item = Item::factory()->for($household)->create();
        $location = Location::factory()->for($household)->create();
        $level = InventoryLevel::factory()->for($item)->for($location)->create(['quantity' => 2]);
        $alert = InventoryAlert::factory()->for($item)->create([
            'household_id' => $household->getKey(),
            'created_by' => $user->getKey(),
        ]);
        $image = ItemImage::factory()->for($item)->create([
            'uploaded_by' => $user->getKey(),
            'caption' => 'Original caption',
        ]);
        $membership->delete();

        $this->assertActionForbidden(fn () => app(CreateInventoryMovement::class)->create(
            $household,
            $user,
            ['movement_type' => 'restock', 'item_id' => $item->getKey(), 'location_id' => $location->getKey(), 'quantity' => 1],
        ));
        $this->assertActionForbidden(fn () => app(ManageInventoryLevel::class)->create(
            $household,
            $user,
            ['item_id' => $item->getKey(), 'location_id' => Location::factory()->for($household)->create()->getKey()],
        ));
        $this->assertActionForbidden(fn () => app(ManageInventoryLevel::class)->update($household, $level, $user, ['alert_threshold' => 1]));
        $this->assertActionForbidden(fn () => app(CreateInventoryAlert::class)->create(
            $household,
            $user,
            ['item_id' => $item->getKey(), 'alert_type' => InventoryAlertType::BuySoon->value],
        ));
        $this->assertActionForbidden(fn () => app(ResolveInventoryAlert::class)->resolve($household, $alert, $user));
        $this->assertActionForbidden(fn () => app(ArchiveItem::class)->archive($household, $item, $user));
        $this->assertActionForbidden(fn () => app(ManageItemImage::class)->update($household, $image, ['caption' => 'Changed'], $user));
        $this->assertActionForbidden(fn () => app(ManageItemImage::class)->delete($household, $image, $user));
        $this->assertActionForbidden(fn () => app(ManageItemImage::class)->restore($household, $image, $user));
        $this->assertActionForbidden(fn () => app(UploadItemImage::class)->upload(
            $household,
            $item,
            $user,
            UploadedFile::fake()->image('photo.png', 40, 30),
        ));

        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('inventory_alerts', 1);
        $this->assertSame(2, $level->fresh()->quantity);
        $this->assertNull($alert->fresh()->resolved_at);
        $this->assertFalse($item->fresh()->trashed());
        $this->assertSame('Original caption', $image->fresh()->caption);
        $this->assertDatabaseCount('item_images', 1);
        Storage::disk('inventory-images')->assertDirectoryEmpty('items/'.$item->getKey());
    }

    private function assertActionForbidden(Closure $operation): void
    {
        try {
            $operation();
            $this->fail('The write action accepted a user without an active household membership.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }
}
