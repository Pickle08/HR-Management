<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiCacheHeadersTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(Role $role, string $email): User
    {
        return User::forceCreate([
            'name' => $role->value,
            'email' => $email,
            'password' => 'password',
            'role' => $role,
            'is_active' => true,
        ]);
    }

    public function test_every_api_response_is_no_store_including_errors(): void
    {
        $karyawan = $this->makeUser(Role::Karyawan, 'kary@t.test');
        $hr = $this->makeUser(Role::Hr, 'hr@t.test');

        $cases = [
            'guest 401' => [null, 'GET', '/api/user', 401],
            'login validation 422' => [null, 'POST', '/api/login', 422],
            'dashboard 200' => [$karyawan, 'GET', '/api/dashboard', 200],
            'report forbidden 403' => [$karyawan, 'GET', '/api/reports/payroll?year=2030&month=3', 403],
            'payroll list 200' => [$hr, 'GET', '/api/payrolls', 200],
            'payroll validation 422' => [$hr, 'POST', '/api/payrolls', 422],
        ];

        foreach ($cases as $label => [$user, $method, $url, $status]) {
            $this->app['auth']->forgetGuards();

            $request = $user ? $this->actingAs($user) : $this;
            $res = $request->json($method, $url)->assertStatus($status);

            $cacheControl = (string) $res->headers->get('Cache-Control');

            $this->assertStringContainsString('no-store', $cacheControl, $label);
            $this->assertStringContainsString('private', $cacheControl, $label);
            $this->assertStringNotContainsString('public', $cacheControl, $label);
        }
    }
}
