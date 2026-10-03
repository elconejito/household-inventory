<?php

namespace Tests\Feature\Policies;

use App\Enums\MembershipRole;
use App\Models\Category;
use App\Models\Household;
use App\Models\Membership;
use App\Models\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NotePolicyTest extends TestCase
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
    public function test_all_household_roles_can_manage_notes(MembershipRole $role): void
    {
        $user = User::factory()->create();
        $household = Household::factory()->create();
        Membership::factory()->for($household)->for($user)->create(['role' => $role]);
        $category = $household->categories()->create(['name' => 'Kitchen']);
        $note = $category->notes()->make(['body' => 'Note']);
        $note->created_by = $user->id;
        $note->save();
        $gate = Gate::forUser($user);

        $this->assertTrue($gate->allows('viewAny', [Note::class, $category]));
        $this->assertTrue($gate->allows('create', [Note::class, $category]));
        $this->assertTrue($gate->allows('view', $note));
        $this->assertTrue($gate->allows('update', $note));
        $this->assertTrue($gate->allows('delete', $note));
        $this->assertTrue($gate->allows('restore', $note));
        $this->assertSame($role === MembershipRole::Owner, $gate->allows('forceDelete', $note));
    }

    public function test_users_outside_the_parent_household_cannot_manage_notes(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->create();
        $note = $category->notes()->create(['body' => 'Private']);
        $gate = Gate::forUser($user);

        $this->assertFalse($gate->allows('viewAny', [Note::class, $category]));
        $this->assertFalse($gate->allows('create', [Note::class, $category]));
        $this->assertFalse($gate->allows('view', $note));
        $this->assertFalse($gate->allows('update', $note));
        $this->assertFalse($gate->allows('delete', $note));
        $this->assertFalse($gate->allows('restore', $note));
    }

    public function test_notes_on_archived_parents_are_read_only(): void
    {
        $user = User::factory()->create();
        $household = Household::factory()->create();
        Membership::factory()->for($household)->for($user)->create();
        $category = $household->categories()->create(['name' => 'Archived']);
        $category->delete();
        $note = $category->notes()->make(['body' => 'Read only']);
        $note->created_by = $user->id;
        $note->save();
        $note->setRelation('notable', $category);
        $gate = Gate::forUser($user);

        $this->assertTrue($gate->allows('view', $note));
        $this->assertFalse($gate->allows('create', [Note::class, $category]));
        $this->assertFalse($gate->allows('update', $note));
        $this->assertFalse($gate->allows('delete', $note));
        $this->assertFalse($gate->allows('restore', $note));
    }
}
