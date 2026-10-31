<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Tanpa header Origin dari domain stateful, Sanctum menganggap
        // request ini bukan SPA dan session tidak aktif.
        config(['sanctum.stateful' => ['localhost:5173']]);
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    private function makeUser(array $attrs = []): User
    {
        return User::forceCreate(array_merge([
            'name' => 'Tester',
            'email' => 'tester@hrm.test',
            'password' => 'password',
            'role' => Role::Hr,
            'is_active' => true,
        ], $attrs));
    }

    public function test_login_with_valid_credentials(): void
    {
        $this->makeUser();

        $this->postJson('/api/login', [
            'email' => 'tester@hrm.test',
            'password' => 'password',
        ])->assertOk()->assertJsonPath('role', 'hr');

        $this->assertAuthenticated();
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $this->makeUser();

        $this->postJson('/api/login', [
            'email' => 'tester@hrm.test',
            'password' => 'salah',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertGuest();
    }

    public function test_inactive_user_cannot_login(): void
    {
        $this->makeUser(['is_active' => false]);

        $this->postJson('/api/login', [
            'email' => 'tester@hrm.test',
            'password' => 'password',
        ])->assertUnprocessable();

        $this->assertGuest();
    }

    public function test_guest_cannot_access_user_endpoint(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_authenticated_user_can_fetch_own_profile(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('email', 'tester@hrm.test');
    }
}
