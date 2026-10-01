<?php

namespace Tests\Feature\Api;

use App\Models\Household;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_registration_creates_and_authenticates_the_user_with_an_owner_membership(): void
    {
        $response = $this->withHeader('Origin', 'http://localhost')
            ->postJson('/api/register', [
                'data' => [
                    'name' => 'Avery Ramos',
                    'email' => 'AVERY@example.com',
                    'password' => 'correct-horse-battery',
                    'password_confirmation' => 'correct-horse-battery',
                    'household_name' => 'Ramos Home',
                ],
            ]);

        $user = User::query()->where('email', 'avery@example.com')->firstOrFail();
        $household = Household::query()->where('name', 'Ramos Home')->firstOrFail();

        $response->assertCreated()->assertExactJson([
            'data' => [
                'type' => 'users',
                'id' => (string) $user->id,
                'name' => 'Avery Ramos',
                'email' => 'avery@example.com',
            ],
        ]);
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertDatabaseHas('memberships', [
            'household_id' => $household->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, Household::query()->count());
        $this->assertSame(1, Membership::query()->count());
    }

    public function test_registration_returns_validation_errors_in_the_global_error_envelope(): void
    {
        $response = $this->withHeader('Origin', 'http://localhost')
            ->postJson('/api/register', ['data' => [
                'name' => 'Avery Ramos',
                'email' => 'avery@example.com',
                'password' => 'correct-horse-battery',
                'password_confirmation' => 'different-password',
            ]]);

        $response->assertUnprocessable()
            ->assertJsonPath('errors.0.status', '422')
            ->assertJsonPath('errors.0.code', 'validation_failed')
            ->assertJsonPath('errors.0.source.pointer', '/data/password');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('households', 0);
        $this->assertDatabaseCount('memberships', 0);
    }

    public function test_login_returns_401_for_incorrect_credentials(): void
    {
        User::factory()->create(['email' => 'avery@example.com']);

        $this->withHeader('Origin', 'http://localhost')
            ->postJson('/api/login', [
                'data' => [
                    'email' => 'avery@example.com',
                    'password' => 'incorrect-password',
                ],
            ])
            ->assertUnauthorized()
            ->assertExactJson([
                'errors' => [[
                    'status' => '401',
                    'code' => 'unauthenticated',
                    'title' => 'Unauthenticated',
                    'detail' => 'Authentication is required.',
                ]],
            ]);
    }

    public function test_login_authenticates_the_user_and_returns_default_fields(): void
    {
        $user = User::factory()->create();

        $this->withHeader('Origin', 'http://localhost')
            ->postJson('/api/login', [
                'data' => [
                    'email' => $user->email,
                    'password' => 'password',
                ],
            ])
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'type' => 'users',
                    'id' => (string) $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
            ]);

        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_authenticated_user_endpoint_returns_only_default_user_fields(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->withHeader('Origin', 'http://localhost')
            ->getJson('/api/user')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'type' => 'users',
                    'id' => (string) $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
            ]);
    }

    public function test_current_user_returns_membership_and_household_only_when_requested(): void
    {
        $user = User::factory()->create();
        $household = Household::factory()->create(['name' => 'Ramos Home']);
        $membership = Membership::factory()->for($household)->for($user)->owner()->create();

        $response = $this->actingAs($user, 'web')
            ->withHeader('Origin', 'http://localhost')
            ->getJson('/api/user?include=membership.household');

        $response->assertOk()
            ->assertJsonPath('data.membership.type', 'memberships')
            ->assertJsonPath('data.membership.id', (string) $membership->id)
            ->assertJsonPath('data.membership.role', 'owner')
            ->assertJsonPath('data.membership.household.type', 'households')
            ->assertJsonPath('data.membership.household.id', (string) $household->id)
            ->assertJsonPath('data.membership.household.name', 'Ramos Home');
    }

    public function test_current_user_rejects_an_unknown_relationship_include(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->withHeader('Origin', 'http://localhost')
            ->getJson('/api/user?include=secrets')
            ->assertBadRequest()
            ->assertJsonPath('errors.0.code', 'invalid_query_parameter');
    }

    public function test_current_user_returns_401_without_authentication(): void
    {
        $this->withHeader('Origin', 'http://localhost')
            ->getJson('/api/user')
            ->assertUnauthorized();
    }

    public function test_logout_returns_401_without_authentication(): void
    {
        $this->withHeader('Origin', 'http://localhost')
            ->postJson('/api/logout')
            ->assertUnauthorized();
    }

    public function test_logout_invalidates_the_session_and_returns_a_data_envelope(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web');

        $this->withHeader('Origin', 'http://localhost')
            ->postJson('/api/logout')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'type' => 'sessions',
                    'id' => 'current',
                    'status' => 'logged_out',
                ],
            ]);

        $this->assertGuest('web');
    }
}
