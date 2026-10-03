<?php

namespace Tests\Feature\Api;

use App\Enums\InventoryAlertType;
use App\Enums\MembershipRole;
use App\Enums\MovementType;
use App\Models\Household;
use App\Models\InventoryLevel;
use App\Models\Item;
use App\Models\ItemImage;
use App\Models\Location;
use App\Models\Membership;
use App\Models\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PermanentDeletionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_owner_permanently_deletes_archived_item_and_all_dependent_history_and_files(): void
    {
        [$user, $household] = $this->householdMember(MembershipRole::Owner);
        Storage::fake('inventory-images');
        $item = Item::factory()->for($household)->create();
        $category = $household->categories()->create(['name' => 'Kitchen']);
        $item->categories()->attach($category);
        $location = $household->locations()->create(['name' => 'Pantry']);
        InventoryLevel::factory()->for($item)->for($location)->create(['quantity' => 0]);
        $movement = $household->inventoryMovements()->create([
            'item_id' => $item->id,
            'movement_type' => MovementType::Restock,
            'recorded_by' => $user->id,
            'recorded_at' => now(),
        ]);
        $entry = $movement->entries()->create([
            'location_id' => $location->id,
            'quantity_delta' => 1,
            'balance_after' => 1,
        ]);
        $alert = $household->inventoryAlerts()->create([
            'item_id' => $item->id,
            'alert_type' => InventoryAlertType::BuySoon,
            'created_by' => $user->id,
            'created_at' => now(),
        ]);
        $notes = collect([$item, $movement, $alert])->map(function ($parent) use ($user): Note {
            $note = $parent->notes()->create(['body' => 'Keep no longer']);
            $note->created_by = $user->id;
            $note->save();

            return $note;
        });
        $image = ItemImage::factory()->for($item)->create([
            'thumbnail_path' => 'items/1/one/thumb.webp',
            'display_path' => 'items/1/one/display.webp',
        ]);
        $archivedImage = ItemImage::factory()->for($item)->create([
            'thumbnail_path' => 'items/1/two/thumb.webp',
            'display_path' => 'items/1/two/display.webp',
        ]);
        $archivedImage->delete();
        Storage::disk('inventory-images')->put($image->thumbnail_path, 'thumb-one');
        Storage::disk('inventory-images')->put($image->display_path, 'display-one');
        Storage::disk('inventory-images')->put($archivedImage->thumbnail_path, 'thumb-two');
        Storage::disk('inventory-images')->put($archivedImage->display_path, 'display-two');
        $item->delete();

        $this->actingAs($user, 'web')->deleteJson('/api/items/'.$item->id.'/permanently')->assertNoContent();

        $this->assertDatabaseMissing('items', ['id' => $item->id]);
        $this->assertDatabaseMissing('category_item', ['category_id' => $category->id, 'item_id' => $item->id]);
        $this->assertDatabaseMissing('inventory_levels', ['item_id' => $item->id]);
        $this->assertDatabaseMissing('inventory_movements', ['id' => $movement->id]);
        $this->assertDatabaseMissing('inventory_movement_entries', ['id' => $entry->id]);
        $this->assertDatabaseMissing('inventory_alerts', ['id' => $alert->id]);
        foreach ($notes as $note) {
            $this->assertDatabaseMissing('notes', ['id' => $note->id]);
        }
        $this->assertDatabaseMissing('item_images', ['id' => $image->id]);
        $this->assertDatabaseMissing('item_images', ['id' => $archivedImage->id]);
        Storage::disk('inventory-images')->assertMissing($image->thumbnail_path);
        Storage::disk('inventory-images')->assertMissing($image->display_path);
        Storage::disk('inventory-images')->assertMissing($archivedImage->thumbnail_path);
        Storage::disk('inventory-images')->assertMissing($archivedImage->display_path);
    }

    public function test_archived_item_permanent_delete_requires_owner_and_active_item_is_rejected(): void
    {
        [$owner, $household] = $this->householdMember(MembershipRole::Owner);
        [$member] = $this->householdMember(MembershipRole::Member, $household);
        $activeItem = Item::factory()->for($household)->create();
        $archivedItem = Item::factory()->for($household)->create();
        $archivedItem->delete();
        $this->assertFalse(Gate::forUser($member)->allows('forceDelete', $archivedItem));

        $this->actingAs($owner, 'web')->deleteJson('/api/items/'.$activeItem->id.'/permanently')
            ->assertConflict();
        $this->app['auth']->forgetGuards();
        $this->actingAs($member, 'web')->deleteJson('/api/items/'.$archivedItem->id.'/permanently')
            ->assertForbidden();

        $this->assertModelExists($activeItem);
        $this->assertModelExists($archivedItem);
    }

    public function test_foreign_archived_item_is_hidden_with_404(): void
    {
        [$user] = $this->householdMember(MembershipRole::Owner);
        $foreignItem = Item::factory()->create();
        $foreignItem->delete();

        $this->actingAs($user, 'web')->deleteJson('/api/items/'.$foreignItem->id.'/permanently')
            ->assertNotFound();

        $this->assertModelExists($foreignItem);
    }

    public function test_each_permanent_delete_endpoint_requires_an_archived_record(): void
    {
        [$user, $household] = $this->householdMember(MembershipRole::Owner);
        $item = Item::factory()->for($household)->create();
        $category = $household->categories()->create(['name' => 'Active category']);
        $location = $household->locations()->create(['name' => 'Active location']);
        $note = $item->notes()->create(['body' => 'Active note']);
        $image = ItemImage::factory()->for($item)->create();
        $urls = [
            '/api/items/'.$item->id.'/permanently',
            '/api/categories/'.$category->id.'/permanently',
            '/api/locations/'.$location->id.'/permanently',
            '/api/notes/'.$note->id.'/permanently',
            '/api/item-images/'.$image->id.'/permanently',
        ];

        foreach ($urls as $url) {
            $this->actingAs($user, 'web')->deleteJson($url)->assertConflict();
        }

        $this->assertModelExists($item);
        $this->assertModelExists($category);
        $this->assertModelExists($location);
        $this->assertModelExists($note);
        $this->assertModelExists($image);
    }

    public function test_each_permanent_delete_endpoint_returns_401_without_authentication(): void
    {
        foreach ([
            '/api/items/1/permanently',
            '/api/categories/1/permanently',
            '/api/locations/1/permanently',
            '/api/notes/1/permanently',
            '/api/item-images/1/permanently',
        ] as $url) {
            $this->deleteJson($url)->assertUnauthorized();
        }
    }

    public function test_each_permanent_delete_endpoint_forbids_household_members(): void
    {
        [, $household] = $this->householdMember(MembershipRole::Owner);
        [$member] = $this->householdMember(MembershipRole::Member, $household);
        $item = Item::factory()->for($household)->create();
        $item->delete();
        $category = $household->categories()->create(['name' => 'Archived category']);
        $category->delete();
        $location = $household->locations()->create(['name' => 'Archived location']);
        $location->delete();
        $note = $category->notes()->create(['body' => 'Archived note']);
        $note->delete();
        $image = ItemImage::factory()->for($item)->create();
        $image->delete();
        $urls = [
            '/api/items/'.$item->id.'/permanently',
            '/api/categories/'.$category->id.'/permanently',
            '/api/locations/'.$location->id.'/permanently',
            '/api/notes/'.$note->id.'/permanently',
            '/api/item-images/'.$image->id.'/permanently',
        ];

        foreach ($urls as $url) {
            $this->app['auth']->forgetGuards();
            $this->actingAs($member, 'web')->deleteJson($url)->assertForbidden();
        }

        $this->assertModelExists($item);
        $this->assertModelExists($category);
        $this->assertModelExists($location);
        $this->assertModelExists($note);
        $this->assertModelExists($image);
    }

    public function test_each_permanent_delete_endpoint_hides_foreign_resources_with_404(): void
    {
        [$user] = $this->householdMember(MembershipRole::Owner);
        $foreignHousehold = Household::factory()->create();
        $item = Item::factory()->for($foreignHousehold)->create();
        $item->delete();
        $category = $foreignHousehold->categories()->create(['name' => 'Foreign archived']);
        $category->delete();
        $location = $foreignHousehold->locations()->create(['name' => 'Foreign archived']);
        $location->delete();
        $note = $category->notes()->create(['body' => 'Foreign archived note']);
        $note->delete();
        $image = ItemImage::factory()->for($item)->create();
        $image->delete();
        $urls = [
            '/api/items/'.$item->id.'/permanently',
            '/api/categories/'.$category->id.'/permanently',
            '/api/locations/'.$location->id.'/permanently',
            '/api/notes/'.$note->id.'/permanently',
            '/api/item-images/'.$image->id.'/permanently',
        ];

        foreach ($urls as $url) {
            $this->app['auth']->forgetGuards();
            $this->actingAs($user, 'web')->deleteJson($url)->assertNotFound();
        }

        $this->assertModelExists($item);
        $this->assertModelExists($category);
        $this->assertModelExists($location);
        $this->assertModelExists($note);
        $this->assertModelExists($image);
    }

    public function test_location_permanent_delete_reports_archived_children_zero_levels_and_movement_entries(): void
    {
        [$user, $household] = $this->householdMember(MembershipRole::Owner);
        $location = $household->locations()->create(['name' => 'Storage']);
        $child = Location::factory()->for($household)->create();
        $child->forceFill(['parent_id' => $location->id])->save();
        $child->delete();
        $item = Item::factory()->for($household)->create();
        InventoryLevel::factory()->for($item)->for($location)->create(['quantity' => 0]);
        $movement = $household->inventoryMovements()->create([
            'item_id' => $item->id,
            'movement_type' => MovementType::Restock,
            'recorded_by' => $user->id,
            'recorded_at' => now(),
        ]);
        $movement->entries()->create(['location_id' => $location->id, 'quantity_delta' => 1, 'balance_after' => 1]);
        $location->delete();

        $this->actingAs($user, 'web')->deleteJson('/api/locations/'.$location->id.'/permanently')
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'permanent_deletion_blocked')
            ->assertJsonPath('errors.0.meta.blockers', ['child locations', 'inventory levels', 'movement entries']);

        $this->assertModelExists($location);
    }

    public function test_owner_permanently_deletes_archived_unreferenced_location(): void
    {
        [$user, $household] = $this->householdMember(MembershipRole::Owner);
        $location = $household->locations()->create(['name' => 'Unused']);
        $location->delete();

        $this->actingAs($user, 'web')->deleteJson('/api/locations/'.$location->id.'/permanently')->assertNoContent();

        $this->assertDatabaseMissing('locations', ['id' => $location->id]);
    }

    public function test_owner_permanently_deletes_archived_note_even_when_parent_is_archived(): void
    {
        [$user, $household] = $this->householdMember(MembershipRole::Owner);
        $category = $household->categories()->create(['name' => 'Old category']);
        $category->delete();
        $note = $category->notes()->create(['body' => 'Archived parent note']);
        $note->delete();

        $this->actingAs($user, 'web')->deleteJson('/api/notes/'.$note->id.'/permanently')->assertNoContent();

        $this->assertDatabaseMissing('notes', ['id' => $note->id]);
    }

    public function test_owner_permanently_deletes_archived_category_and_detaches_items_and_notes(): void
    {
        [$user, $household] = $this->householdMember(MembershipRole::Owner);
        $category = $household->categories()->create(['name' => 'Seasonal']);
        $item = Item::factory()->for($household)->create();
        $category->items()->attach($item);
        $note = $category->notes()->create(['body' => 'Old note']);
        $category->delete();

        $this->actingAs($user, 'web')->deleteJson('/api/categories/'.$category->id.'/permanently')->assertNoContent();

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
        $this->assertDatabaseMissing('category_item', ['category_id' => $category->id, 'item_id' => $item->id]);
        $this->assertDatabaseMissing('notes', ['id' => $note->id]);
        $this->assertModelExists($item);
    }

    public function test_owner_permanently_deletes_archived_image_from_archived_item(): void
    {
        [$user, $household] = $this->householdMember(MembershipRole::Owner);
        Storage::fake('inventory-images');
        $item = Item::factory()->for($household)->create();
        $image = ItemImage::factory()->for($item)->create([
            'thumbnail_path' => 'items/archived/thumb.webp',
            'display_path' => 'items/archived/display.webp',
        ]);
        $image->delete();
        $item->delete();
        Storage::disk('inventory-images')->put($image->thumbnail_path, 'thumbnail');
        Storage::disk('inventory-images')->put($image->display_path, 'display');

        $this->actingAs($user, 'web')->deleteJson('/api/item-images/'.$image->id.'/permanently')->assertNoContent();

        $this->assertDatabaseMissing('item_images', ['id' => $image->id]);
        Storage::disk('inventory-images')->assertMissing($image->thumbnail_path);
        Storage::disk('inventory-images')->assertMissing($image->display_path);
        $this->assertModelExists($item);
    }

    public function test_failed_image_file_deletion_preserves_image_record_and_both_derivatives(): void
    {
        [$user, $household] = $this->householdMember(MembershipRole::Owner);
        Storage::fake('inventory-images');
        $item = Item::factory()->for($household)->create();
        $image = ItemImage::factory()->for($item)->create([
            'thumbnail_path' => 'items/test/thumb.webp',
            'display_path' => 'items/test/display.webp',
        ]);
        $image->delete();
        $disk = Storage::disk('inventory-images');
        $disk->put($image->thumbnail_path, 'thumbnail');
        $disk->put($image->display_path, 'display');
        $partialDisk = Mockery::mock($disk)->makePartial();
        $partialDisk->shouldReceive('delete')->once()->with($image->thumbnail_path)
            ->andReturnUsing(fn (string $path): bool => $disk->delete($path));
        $partialDisk->shouldReceive('delete')->once()->with($image->display_path)->andReturnFalse();
        Storage::shouldReceive('disk')->with('inventory-images')->andReturn($partialDisk);

        $this->actingAs($user, 'web')->deleteJson('/api/item-images/'.$image->id.'/permanently')->assertServerError();

        $this->assertDatabaseHas('item_images', ['id' => $image->id]);
        $this->assertSame('thumbnail', $disk->get($image->thumbnail_path));
        $this->assertSame('display', $disk->get($image->display_path));
    }

    public function test_database_failure_after_image_file_deletion_restores_files_and_archived_record(): void
    {
        [$user, $household] = $this->householdMember(MembershipRole::Owner);
        Storage::fake('inventory-images');
        $item = Item::factory()->for($household)->create();
        $image = ItemImage::factory()->for($item)->create([
            'thumbnail_path' => 'items/test/rollback-thumb.webp',
            'display_path' => 'items/test/rollback-display.webp',
        ]);
        $image->delete();
        $disk = Storage::disk('inventory-images');
        $disk->put($image->thumbnail_path, 'thumbnail');
        $disk->put($image->display_path, 'display');
        $connection = DB::connection();
        DB::shouldReceive('transaction')->once()->andReturnUsing(function (callable $callback) use ($connection): void {
            $connection->transaction(function () use ($callback): void {
                $callback();
                throw new RuntimeException('Simulated transaction commit failure.');
            });
        });

        $this->actingAs($user, 'web')->deleteJson('/api/item-images/'.$image->id.'/permanently')->assertServerError();

        $this->assertTrue($connection->table('item_images')->where('id', $image->id)->exists());
        $this->assertSame('thumbnail', $disk->get($image->thumbnail_path));
        $this->assertSame('display', $disk->get($image->display_path));
    }

    /** @return array{User, Household} */
    private function householdMember(MembershipRole $role, ?Household $household = null): array
    {
        $user = User::factory()->create();
        $household ??= Household::factory()->create();
        Membership::factory()->for($household)->for($user)->create(['role' => $role]);

        return [$user, $household];
    }
}
