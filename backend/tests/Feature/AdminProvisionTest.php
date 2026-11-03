<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminProvisionTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: int, 1: string} */
    private function provision(array $args): array
    {
        $code = Artisan::call('hrm:admin', $args);

        return [$code, Artisan::output()];
    }

    private function passwordFrom(string $output): string
    {
        $this->assertSame(1, preg_match('/Password sementara: (\S+)/', $output, $matches));

        return $matches[1];
    }

    private function makeUser(Role $role, string $email, bool $active = true, string $password = 'password'): User
    {
        return User::forceCreate([
            'name' => $role->value,
            'email' => $email,
            'password' => $password,
            'role' => $role,
            'is_active' => $active,
        ]);
    }

    public function test_creates_first_super_admin_with_one_time_password(): void
    {
        [$code, $out] = $this->provision(['email' => 'Root@Example.com', '--name' => 'Admin Utama']);

        $this->assertSame(0, $code);

        $password = $this->passwordFrom($out);
        $user = User::where('email', 'root@example.com')->firstOrFail();

        $this->assertSame(Role::SuperAdmin, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertSame('Admin Utama', $user->name);
        $this->assertSame(14, strlen($password));
        $this->assertTrue(Hash::check($password, $user->password));

        $log = AuditLog::where('action', 'admin.provisioned')->firstOrFail();

        $this->assertSame('created', $log->new_values['mode']);
        $this->assertNull($log->user_id);

        foreach (AuditLog::all() as $row) {
            $this->assertStringNotContainsString($password, json_encode([$row->old_values, $row->new_values]));
        }
    }

    public function test_resets_locked_super_admin_and_revokes_tokens(): void
    {
        $admin = $this->makeUser(Role::SuperAdmin, 'root@example.com', false, 'lama');
        $admin->createToken('t');

        [$code, $out] = $this->provision(['email' => 'root@example.com']);

        $this->assertSame(0, $code);

        $password = $this->passwordFrom($out);
        $fresh = $admin->fresh();

        $this->assertTrue(Hash::check($password, $fresh->password));
        $this->assertFalse(Hash::check('lama', $fresh->password));
        $this->assertTrue($fresh->is_active);
        $this->assertSame(0, $fresh->tokens()->count());
        $this->assertSame(1, User::count());
        $this->assertSame(
            'password_reset',
            AuditLog::where('action', 'admin.provisioned')->firstOrFail()->new_values['mode']
        );
    }

    public function test_refuses_other_roles_missing_name_and_bad_email(): void
    {
        $hr = $this->makeUser(Role::Hr, 'hr@t.test', true, 'rahasia');

        [$code] = $this->provision(['email' => 'hr@t.test']);
        $this->assertSame(1, $code);
        $this->assertTrue(Hash::check('rahasia', $hr->fresh()->password));
        $this->assertSame(Role::Hr, $hr->fresh()->role);

        [$code] = $this->provision(['email' => 'baru@t.test']);
        $this->assertSame(1, $code);
        $this->assertDatabaseMissing('users', ['email' => 'baru@t.test']);

        [$code] = $this->provision(['email' => 'bukan-email', '--name' => 'X']);
        $this->assertSame(1, $code);

        $this->assertSame(0, AuditLog::where('action', 'admin.provisioned')->count());
    }
}
