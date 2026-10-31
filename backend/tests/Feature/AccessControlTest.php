<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Route "probe" khusus test, cuma buat ngetes middleware.
        Route::middleware(['api', 'auth:sanctum', 'active', 'role:hr,super_admin'])
            ->get('/api/_probe', fn() => response()->json(['ok' => true]));
    }

    private function makeUser(Role $role, bool $active = true): User
    {
        return User::forceCreate([
            'name' => 'Tester',
            'email' => $role->value . '@hrm.test',
            'password' => 'password',
            'role' => $role,
            'is_active' => $active,
        ]);
    }

    public function test_allowed_role_can_access(): void
    {
        $this->actingAs($this->makeUser(Role::Hr))
            ->getJson('/api/_probe')
            ->assertOk();
    }

    public function test_other_role_gets_403(): void
    {
        $this->actingAs($this->makeUser(Role::Karyawan))
            ->getJson('/api/_probe')
            ->assertForbidden();
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/_probe')->assertUnauthorized();
    }

    public function test_deactivated_user_with_live_session_is_rejected(): void
    {
        $this->actingAs($this->makeUser(Role::Hr, active: false))
            ->getJson('/api/_probe')
            ->assertUnauthorized();
    }

    public function test_deactivated_user_cannot_use_user_endpoint(): void
    {
        $this->actingAs($this->makeUser(Role::Hr, active: false))
            ->getJson('/api/user')
            ->assertUnauthorized();
    }
}
