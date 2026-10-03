<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Household;
use App\Models\InventoryAlert;
use App\Models\InventoryMovement;
use App\Models\Item;
use App\Models\Membership;
use App\Models\Note;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NoteTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_note_endpoints_require_authentication(): void
    {
        $this->getJson('/api/categories/1/notes')->assertUnauthorized();
        $this->postJson('/api/categories/1/notes', ['data' => ['body' => 'Text']])->assertUnauthorized();
        $this->patchJson('/api/notes/1', ['data' => ['body' => 'Text']])->assertUnauthorized();
    }

    public function test_member_creates_and_edits_plain_text_note_with_immutable_author(): void
    {
        [$user, $category] = $this->householdCategory();
        $this->actingAs($user, 'web');

        $this->travelTo(Carbon::parse('2026-10-03 12:00:00.000000'));
        $created = $this->postJson('/api/categories/'.$category->id.'/notes?include=created_by', [
            'data' => ['body' => "\n First line\n\nThird line \n"],
        ]);
        $created->assertCreated()
            ->assertJsonPath('data.type', 'notes')
            ->assertJsonPath('data.body', "\n First line\n\nThird line \n")
            ->assertJsonPath('data.is_edited', false)
            ->assertJsonPath('data.created_by.type', 'users')
            ->assertJsonPath('data.created_by.id', (string) $user->id);
        $note = Note::query()->firstOrFail();
        $this->assertSame($user->id, $note->created_by);

        $this->travelTo(Carbon::parse('2026-10-03 12:00:00.100000'));
        $this->patchJson('/api/notes/'.$note->id, ['data' => ['body' => 'Edited']])
            ->assertOk()
            ->assertJsonPath('data.body', 'Edited')
            ->assertJsonPath('data.is_edited', true);
        $this->assertSame($user->id, $note->fresh()->created_by);

        $updatedAt = $note->fresh()->updated_at;
        $this->travelTo(Carbon::parse('2026-10-03 12:01:00.000000'));
        $this->patchJson('/api/notes/'.$note->id, ['data' => ['body' => 'Edited']])->assertOk();
        $this->assertTrue($updatedAt->equalTo($note->fresh()->updated_at));
        $this->travelBack();
    }

    public function test_note_index_is_newest_first_paginated_and_has_optional_author(): void
    {
        [$user, $category] = $this->householdCategory();
        $first = $this->createNote($category, 'Older', $user);
        $second = $this->createNote($category, 'Newer', $user);

        $response = $this->actingAs($user, 'web')->getJson('/api/categories/'.$category->id.'/notes?per_page=10&include=created_by');

        $response->assertOk()
            ->assertJsonPath('data.0.id', (string) $second->id)
            ->assertJsonPath('data.0.created_by.id', (string) $user->id)
            ->assertJsonPath('data.1.id', (string) $first->id)
            ->assertJsonPath('meta.total', 2);
    }

    public function test_note_index_can_sort_backdated_notes_by_creation_date(): void
    {
        [$user, $category] = $this->householdCategory();
        $later = $this->createNote($category, 'Later date', $user);
        $this->travelTo(Carbon::parse('2026-09-01 10:00:00.000000'));
        $earlier = $this->createNote($category, 'Earlier date', $user);
        $this->travelBack();

        $this->actingAs($user, 'web')->getJson('/api/categories/'.$category->id.'/notes?sort=created_at')
            ->assertOk()
            ->assertJsonPath('data.0.id', (string) $earlier->id)
            ->assertJsonPath('data.1.id', (string) $later->id);
    }

    public static function notableTypes(): array
    {
        return [
            'item' => ['items'],
            'category' => ['categories'],
            'location' => ['locations'],
            'inventory movement' => ['inventory-movements'],
            'inventory alert' => ['inventory-alerts'],
        ];
    }

    #[DataProvider('notableTypes')]
    public function test_collection_and_create_routes_support_each_notable_type(string $notableType): void
    {
        [$user, $category] = $this->householdCategory();
        $household = $category->household;
        $item = Item::factory()->for($household)->create();
        $notable = match ($notableType) {
            'items' => $item,
            'categories' => $category,
            'locations' => $household->locations()->create(['name' => 'Basement']),
            'inventory-movements' => InventoryMovement::factory()->create([
                'household_id' => $household->id,
                'item_id' => $item->id,
                'recorded_by' => $user->id,
            ]),
            'inventory-alerts' => InventoryAlert::factory()->create([
                'household_id' => $household->id,
                'item_id' => $item->id,
                'created_by' => $user->id,
            ]),
        };
        $base = '/api/'.$notableType.'/'.$notable->id.'/notes';
        $this->actingAs($user, 'web');

        $this->postJson($base, ['data' => ['body' => 'All parent types work']])->assertCreated();
        $this->getJson($base)->assertOk()->assertJsonCount(1, 'data');
    }

    #[DataProvider('notableTypes')]
    public function test_parent_transforms_can_include_notes_and_their_authors(string $notableType): void
    {
        [$user, $category] = $this->householdCategory();
        $household = $category->household;
        $item = Item::factory()->for($household)->create();
        $notable = match ($notableType) {
            'items' => $item,
            'categories' => $category,
            'locations' => $household->locations()->create(['name' => 'Basement']),
            'inventory-movements' => InventoryMovement::factory()->create([
                'household_id' => $household->id,
                'item_id' => $item->id,
                'recorded_by' => $user->id,
            ]),
            'inventory-alerts' => InventoryAlert::factory()->create([
                'household_id' => $household->id,
                'item_id' => $item->id,
                'created_by' => $user->id,
            ]),
        };
        $note = $this->createNote($notable, 'Parent embedding', $user);
        $route = '/api/'.$notableType.'/'.$notable->id;

        $this->actingAs($user, 'web')->getJson($route.'?include=notes.created_by')
            ->assertOk()
            ->assertJsonPath('data.notes.0.id', (string) $note->id)
            ->assertJsonPath('data.notes.0.created_by.id', (string) $user->id);

        $this->getJson('/api/'.$notableType.'?include=notes.created_by')
            ->assertOk()
            ->assertJsonPath('data.0.notes.0.id', (string) $note->id)
            ->assertJsonPath('data.0.notes.0.created_by.id', (string) $user->id);
    }

    public function test_note_payload_rejects_empty_and_oversized_body(): void
    {
        [$user, $category] = $this->householdCategory();
        $this->actingAs($user, 'web');

        $this->postJson('/api/categories/'.$category->id.'/notes', ['data' => ['body' => '']])->assertUnprocessable();
        $this->postJson('/api/categories/'.$category->id.'/notes', ['data' => ['body' => str_repeat('a', 10001)]])->assertUnprocessable();
        $this->assertDatabaseCount('notes', 0);
    }

    public function test_note_author_cannot_be_supplied_by_the_client(): void
    {
        [$user, $category] = $this->householdCategory();

        $this->actingAs($user, 'web')->postJson('/api/categories/'.$category->id.'/notes', [
            'data' => ['body' => 'Text', 'created_by' => $user->id],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('notes', 0);
    }

    public function test_note_writes_return_422_for_unexpected_outer_fields_without_changes(): void
    {
        [$user, $category] = $this->householdCategory();
        $note = Note::factory()->for($category, 'notable')->create(['body' => 'Keep this body', 'created_by' => $user->id]);
        $this->actingAs($user, 'web');

        $this->postJson('/api/categories/'.$category->id.'/notes?include=created_by', [
            'data' => ['body' => 'New note'], 'household_id' => 999,
        ])->assertUnprocessable()->assertJsonPath('errors.0.detail', 'This field is not allowed.');
        $this->patchJson('/api/notes/'.$note->id.'?include=created_by', [
            'data' => ['body' => 'Changed'], 'created_by' => 999,
        ])->assertUnprocessable()->assertJsonPath('errors.0.detail', 'This field is not allowed.');

        $this->assertDatabaseCount('notes', 1);
        $this->assertSame('Keep this body', $note->fresh()->body);
    }

    public function test_notes_are_hidden_across_households(): void
    {
        [$user] = $this->householdCategory();
        $foreignCategory = Category::factory()->create();
        $foreignNote = $foreignCategory->notes()->create(['body' => 'Private']);
        $this->actingAs($user, 'web');

        $this->getJson('/api/categories/'.$foreignCategory->id.'/notes')->assertNotFound();
        $this->patchJson('/api/notes/'.$foreignNote->id, ['data' => ['body' => 'Changed']])->assertNotFound();
    }

    public function test_archived_parent_notes_remain_readable_but_cannot_be_changed_or_restored(): void
    {
        [$user, $category] = $this->householdCategory();
        $note = $this->createNote($category, 'Existing', $user);
        $category->delete();
        $this->actingAs($user, 'web');

        $this->getJson('/api/categories/'.$category->id.'/notes')
            ->assertOk()
            ->assertJsonPath('data.0.id', (string) $note->id);
        $this->postJson('/api/categories/'.$category->id.'/notes', ['data' => ['body' => 'New']])->assertForbidden();
        $this->deleteJson('/api/notes/'.$note->id)->assertForbidden();

        $note->delete();
        $this->postJson('/api/notes/'.$note->id.'/restore')->assertForbidden();
    }

    public function test_note_can_be_soft_deleted_and_restored_with_active_parent(): void
    {
        [$user, $category] = $this->householdCategory();
        $note = $this->createNote($category, 'Retained', $user);
        $this->actingAs($user, 'web');

        $this->deleteJson('/api/notes/'.$note->id)->assertNoContent();
        $this->assertSoftDeleted('notes', ['id' => $note->id]);
        $this->postJson('/api/notes/'.$note->id.'/restore')->assertOk()->assertJsonPath('data.body', 'Retained');
        $this->assertNotSoftDeleted('notes', ['id' => $note->id]);
    }

    public function test_deleted_author_is_returned_as_null_when_included(): void
    {
        [$user, $category] = $this->householdCategory();
        $note = $this->createNote($category, 'Author removed', $user);
        $user->delete();

        $newMember = User::factory()->create();
        Membership::factory()->for($category->household)->for($newMember)->create();
        $this->actingAs($newMember, 'web')->getJson('/api/categories/'.$category->id.'/notes')
            ->assertOk()
            ->assertJsonMissingPath('data.0.created_by');

        $this->getJson('/api/categories/'.$category->id.'/notes?include=created_by')
            ->assertOk()
            ->assertJsonPath('data.0.id', (string) $note->id)
            ->assertJsonPath('data.0.created_by', null);
    }

    public function test_hard_deleting_an_item_removes_notes_for_it_and_cascaded_history(): void
    {
        [$user, $category] = $this->householdCategory();
        $household = $category->household;
        $item = Item::factory()->for($household)->create();
        $movement = InventoryMovement::factory()->create([
            'household_id' => $household->id,
            'item_id' => $item->id,
            'recorded_by' => $user->id,
        ]);
        $alert = InventoryAlert::factory()->create([
            'household_id' => $household->id,
            'item_id' => $item->id,
            'created_by' => $user->id,
        ]);
        $itemNote = $this->createNote($item, 'Item', $user);
        $movementNote = $this->createNote($movement, 'Movement', $user);
        $alertNote = $this->createNote($alert, 'Alert', $user);

        $item->forceDelete();

        $this->assertModelMissing($itemNote);
        $this->assertModelMissing($movementNote);
        $this->assertModelMissing($alertNote);
    }

    private function householdCategory(): array
    {
        $user = User::factory()->create();
        $household = Household::factory()->create();
        Membership::factory()->for($household)->for($user)->create();
        $category = $household->categories()->create(['name' => 'Kitchen']);

        return [$user, $category];
    }

    private function createNote(Model $notable, string $body, ?User $author = null): Note
    {
        $note = $notable->notes()->make(['body' => $body]);
        $note->created_by = $author?->getKey();
        $note->save();

        return $note;
    }
}
