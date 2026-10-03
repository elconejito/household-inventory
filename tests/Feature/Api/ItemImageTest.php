<?php

namespace Tests\Feature\Api;

use App\Actions\UploadItemImage;
use App\Models\Household;
use App\Models\Item;
use App\Models\ItemImage;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Throwable;

class ItemImageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_image_endpoints_return_401_without_authentication(): void
    {
        $item = Item::factory()->create();

        $this->getJson('/api/items/'.$item->id.'/images')->assertUnauthorized();
        $this->postJson('/api/items/'.$item->id.'/images', [])->assertUnauthorized();
        $this->getJson('/api/item-images/1/thumbnail')->assertUnauthorized();
    }

    public function test_upload_stores_exactly_two_private_derivatives_and_returns_image_metadata(): void
    {
        Storage::fake('inventory-images');
        [$user, $household] = $this->householdMember();
        $item = Item::factory()->for($household)->create();

        $response = $this->actingAs($user, 'web')->post('/api/items/'.$item->id.'/images', [
            'image' => $this->uploadedPng(),
            'data' => ['caption' => 'Kitchen shelf'],
        ], ['Accept' => 'application/json']);

        $image = ItemImage::query()->firstOrFail();
        $response->assertCreated()
            ->assertJsonPath('data.type', 'item-images')
            ->assertJsonPath('data.id', (string) $image->id)
            ->assertJsonPath('data.caption', 'Kitchen shelf')
            ->assertJsonPath('data.is_primary', true)
            ->assertJsonPath('data.thumbnail_mime_type', 'image/webp')
            ->assertJsonPath('data.display_mime_type', 'image/webp')
            ->assertJsonMissingPath('data.thumbnail_path')
            ->assertJsonMissingPath('data.display_path');

        $this->assertDatabaseCount('item_images', 1);
        $this->assertCount(2, Storage::disk('inventory-images')->allFiles());
        $this->assertSame('image/webp', image_type_to_mime_type(exif_imagetype(Storage::disk('inventory-images')->path($image->thumbnail_path))));
    }

    public function test_upload_rejects_spoofed_metadata_and_unsupported_images(): void
    {
        Storage::fake('inventory-images');
        [$user, $household] = $this->householdMember();
        $item = Item::factory()->for($household)->create();
        $this->actingAs($user, 'web');

        $this->post('/api/items/'.$item->id.'/images', [
            'image' => $this->uploadedPng(),
            'data' => ['caption' => 'Photo', 'is_primary' => true],
            'item_id' => 999,
            'disk' => 'public',
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->post('/api/items/'.$item->id.'/images', [
            'image' => UploadedFile::fake()->createWithContent('vector.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'validation_failed');

        $this->post('/api/items/'.$item->id.'/images', [
            'image' => UploadedFile::fake()->createWithContent('photo.heic', 'HEIC fixture'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()
            ->assertJsonPath('errors.0.detail', 'HEIC and HEIF photos are not supported by this server. Export the photo as JPEG, PNG, or WebP and upload it again.');

        $this->post('/api/items/'.$item->id.'/images', [
            'image' => UploadedFile::fake()->create('large.jpg', 20 * 1024 + 1, 'image/jpeg'),
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->assertDatabaseCount('item_images', 0);
        $this->assertSame([], Storage::disk('inventory-images')->allFiles());
    }

    public function test_database_failure_after_storage_writes_removes_both_derivatives(): void
    {
        Storage::fake('inventory-images');
        [, $household] = $this->householdMember();
        $item = Item::factory()->for($household)->create();
        $unknownUploader = new User;
        $unknownUploader->setAttribute($unknownUploader->getKeyName(), PHP_INT_MAX);

        $failure = null;
        try {
            app(UploadItemImage::class)->upload($household, $item, $unknownUploader, $this->uploadedPng());
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        $this->assertNotNull($failure, 'The invalid uploader reference was persisted.');
        $this->assertDatabaseCount('item_images', 0);
        $this->assertSame([], Storage::disk('inventory-images')->allFiles());
    }

    public function test_image_list_is_primary_first_and_accepts_only_the_uploaded_by_include(): void
    {
        Storage::fake('inventory-images');
        [$user, $household] = $this->householdMember();
        $item = Item::factory()->for($household)->create();
        $older = $this->storedImage($item, $user, ['is_primary' => false, 'uploaded_at' => now()->subDay()]);
        $primary = $this->storedImage($item, $user, ['is_primary' => true, 'uploaded_at' => now()]);
        $this->actingAs($user, 'web');

        $response = $this->getJson('/api/items/'.$item->id.'/images?per_page=10&include=uploaded_by');
        $response->assertOk()
            ->assertJsonPath('data.0.id', (string) $primary->id)
            ->assertJsonPath('data.0.type', 'item-images')
            ->assertJsonPath('data.0.uploaded_by.type', 'users')
            ->assertJsonPath('data.1.id', (string) $older->id);

        $this->getJson('/api/items/'.$item->id.'/images?include=invalid')->assertBadRequest();
        $this->getJson('/api/items/'.$item->id.'/images?per_page=20')->assertUnprocessable();
    }

    public function test_archived_photos_can_be_listed_explicitly_even_for_archived_items(): void
    {
        Storage::fake('inventory-images');
        [$user, $household] = $this->householdMember();
        $item = Item::factory()->for($household)->create();
        $active = $this->storedImage($item, $user, ['is_primary' => true]);
        $archived = $this->storedImage($item, $user, ['is_primary' => false]);
        $archived->delete();
        $item->delete();

        $this->actingAs($user, 'web')->getJson('/api/items/'.$item->id.'/images?filter[trashed]=only')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $archived->id);
        $this->getJson('/api/items/'.$item->id.'/images')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $active->id);
        $this->getJson('/api/items/'.$item->id.'/images?filter[trashed]=invalid')->assertUnprocessable();
    }

    public function test_archived_photos_return_404_for_another_household(): void
    {
        Storage::fake('inventory-images');
        [$user, $household] = $this->householdMember();
        $item = Item::factory()->for($household)->create();
        $this->storedImage($item, $user)->delete();
        $item->delete();
        [$otherUser] = $this->householdMember();

        $this->actingAs($otherUser, 'web')->getJson('/api/items/'.$item->id.'/images?filter[trashed]=only')->assertNotFound();
    }

    public function test_primary_photo_can_be_changed_deleted_and_restored_without_replacing_current_primary(): void
    {
        Storage::fake('inventory-images');
        [$user, $household] = $this->householdMember();
        $item = Item::factory()->for($household)->create();
        $first = $this->storedImage($item, $user, ['is_primary' => true, 'uploaded_at' => now()->subDay()]);
        $second = $this->storedImage($item, $user, ['is_primary' => false]);
        $this->actingAs($user, 'web');

        $this->patchJson('/api/item-images/'.$second->id, ['data' => ['is_primary' => true]])
            ->assertOk()
            ->assertJsonPath('data.is_primary', true);
        $this->assertFalse($first->fresh()->is_primary);

        $this->deleteJson('/api/item-images/'.$second->id)->assertNoContent();
        $this->assertTrue($first->fresh()->is_primary);
        $this->assertSoftDeleted('item_images', ['id' => $second->id]);

        $this->postJson('/api/item-images/'.$second->id.'/restore')
            ->assertOk()
            ->assertJsonPath('data.is_primary', false);
        $this->assertTrue($first->fresh()->is_primary);

        $this->deleteJson('/api/item-images/'.$first->id)->assertNoContent();
        $this->assertTrue($second->fresh()->is_primary);
        $this->assertNotSoftDeleted('item_images', ['id' => $second->id]);
    }

    public function test_image_metadata_update_rejects_false_primary_and_internal_fields(): void
    {
        Storage::fake('inventory-images');
        [$user, $household] = $this->householdMember();
        $item = Item::factory()->for($household)->create();
        $image = $this->storedImage($item, $user, ['is_primary' => true]);
        $this->actingAs($user, 'web');

        $this->patchJson('/api/item-images/'.$image->id, ['data' => ['is_primary' => false]])
            ->assertUnprocessable();
        $this->patchJson('/api/item-images/'.$image->id, ['data' => ['disk' => 'public']])
            ->assertUnprocessable();
        $this->patchJson('/api/item-images/'.$image->id, ['data' => ['caption' => ' Updated  caption ']])
            ->assertOk()
            ->assertJsonPath('data.caption', 'Updated  caption');

        $this->assertSame('inventory-images', $image->fresh()->disk);
        $this->assertTrue($image->fresh()->is_primary);
    }

    public function test_image_writes_accept_explicit_query_includes_without_treating_them_as_body_fields(): void
    {
        Storage::fake('inventory-images');
        [$user, $household] = $this->householdMember();
        $item = Item::factory()->for($household)->create();
        $this->actingAs($user, 'web');

        $created = $this->post('/api/items/'.$item->id.'/images?include=uploaded_by', [
            'image' => $this->uploadedPng(), 'data' => ['caption' => 'Label'],
        ], ['Accept' => 'application/json']);
        $created->assertCreated()->assertJsonPath('data.uploaded_by.id', (string) $user->id);
        $imageId = $created->json('data.id');
        $this->patchJson('/api/item-images/'.$imageId.'?include=uploaded_by', [
            'data' => ['caption' => 'Updated label'],
        ])->assertOk()->assertJsonPath('data.uploaded_by.id', (string) $user->id);

        $this->assertDatabaseHas('item_images', ['id' => $imageId, 'caption' => 'Updated label']);
        $this->assertCount(2, Storage::disk('inventory-images')->allFiles());
    }

    public function test_delivery_is_private_scoped_and_returns_404_for_missing_or_archived_images(): void
    {
        Storage::fake('inventory-images');
        [$user, $household] = $this->householdMember();
        $item = Item::factory()->for($household)->create();
        $image = $this->storedImage($item, $user);
        $this->actingAs($user, 'web');

        $this->get(route('item-images.thumbnail', ['item_image' => $image->id]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cache-Control', 'max-age=300, private');

        Storage::disk('inventory-images')->delete($image->display_path);
        $this->get(route('item-images.display', ['item_image' => $image->id]))->assertNotFound();
        $item->delete();
        $this->get(route('item-images.thumbnail', ['item_image' => $image->id]))->assertNotFound();

        $foreignImage = $this->storedImage(Item::factory()->create(), $user);
        $this->get(route('item-images.thumbnail', ['item_image' => $foreignImage->id]))->assertNotFound();
        $this->actingAs(User::factory()->create(), 'web')
            ->get(route('item-images.thumbnail', ['item_image' => $image->id]))
            ->assertNotFound();
    }

    public function test_item_images_include_is_opt_in_ordered_and_does_not_expose_storage_paths(): void
    {
        Storage::fake('inventory-images');
        [$user, $household] = $this->householdMember();
        $item = Item::factory()->for($household)->create();
        $image = $this->storedImage($item, $user, ['is_primary' => true]);
        $this->actingAs($user, 'web');

        $this->getJson('/api/items/'.$item->id)
            ->assertOk()
            ->assertJsonMissingPath('data.images');

        $this->getJson('/api/items?include=images')
            ->assertOk()
            ->assertJsonPath('data.0.images.0.id', (string) $image->id)
            ->assertJsonPath('data.0.images.0.type', 'item-images')
            ->assertJsonMissingPath('data.0.images.0.thumbnail_path')
            ->assertJsonMissingPath('data.0.images.0.display_path');

        $this->getJson('/api/items?include=images.uploaded_by')
            ->assertOk()
            ->assertJsonPath('data.0.images.0.uploaded_by.type', 'users');
    }

    private function householdMember(): array
    {
        $user = User::factory()->create();
        $household = Household::factory()->create();
        Membership::factory()->for($household)->for($user)->create();

        return [$user, $household];
    }

    private function uploadedPng(): UploadedFile
    {
        $image = imagecreatetruecolor(40, 30);
        ob_start();
        imagepng($image);
        $contents = ob_get_clean();
        imagedestroy($image);

        return UploadedFile::fake()->createWithContent('photo.png', $contents);
    }

    private function storedImage(Item $item, User $uploader, array $attributes = []): ItemImage
    {
        $directory = 'items/'.$item->id.'/'.uniqid('test-', true);
        $thumbnailPath = $directory.'/thumbnail.webp';
        $displayPath = $directory.'/display.webp';
        $storage = Storage::disk('inventory-images');
        $storage->put($thumbnailPath, $this->webp());
        $storage->put($displayPath, $this->webp());

        return ItemImage::factory()->for($item)->create(array_merge([
            'disk' => 'inventory-images',
            'thumbnail_path' => $thumbnailPath,
            'display_path' => $displayPath,
            'uploaded_by' => $uploader->id,
        ], $attributes));
    }

    private function webp(): string
    {
        $image = imagecreatetruecolor(8, 8);
        ob_start();
        imagewebp($image);
        $contents = ob_get_clean();
        imagedestroy($image);

        return $contents;
    }
}
