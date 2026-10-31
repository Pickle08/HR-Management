<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sanctum.stateful' => ['localhost:5173']]);
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    private function makeUser(Role $role = Role::Hr, string $email = 'tester@hrm.test'): User
    {
        return User::forceCreate([
            'name' => 'Tester',
            'email' => $email,
            'password' => 'password',
            'role' => $role,
            'is_active' => true,
        ]);
    }

    public function test_successful_login_is_recorded(): void
    {
        $user = $this->makeUser();

        $this->postJson('/api/login', [
            'email' => 'tester@hrm.test',
            'password' => 'password',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.login',
            'user_id' => $user->id,
        ]);
    }

    public function test_failed_login_is_recorded_without_actor_or_password(): void
    {
        $this->postJson('/api/login', [
            'email' => 'x@hrm.test',
            'password' => 'rahasia123',
        ])->assertUnprocessable();

        $log = AuditLog::where('action', 'auth.login_failed')->firstOrFail();

        $this->assertNull($log->user_id);
        $this->assertSame(['email' => 'x@hrm.test'], $log->new_values);
        $this->assertStringNotContainsString('rahasia123', json_encode($log->getAttributes()));
    }

    public function test_logout_is_recorded_with_actor(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->postJson('/api/logout')->assertNoContent();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.logout',
            'user_id' => $user->id,
        ]);
    }

    public function test_role_change_is_recorded_with_old_new_values_and_actor(): void
    {
        $admin = $this->makeUser(Role::SuperAdmin, 'admin@hrm.test');
        $target = $this->makeUser(Role::Karyawan, 'target@hrm.test');

        $this->actingAs($admin);
        $target->forceFill(['role' => Role::Hr])->save();

        $log = AuditLog::where('action', 'user.updated')
            ->where('auditable_id', $target->id)
            ->firstOrFail();

        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame(['role' => 'karyawan'], $log->old_values);
        $this->assertSame(['role' => 'hr'], $log->new_values);
    }

    public function test_password_change_is_recorded_but_masked(): void
    {
        $user = $this->makeUser();

        $user->update(['password' => 'password-baru']);

        $log = AuditLog::where('action', 'user.updated')->firstOrFail();

        $this->assertSame('[disembunyikan]', $log->new_values['password']);
    }

    public function test_audit_log_cannot_be_updated(): void
    {
        $log = Audit::record('test.action');

        $this->expectException(LogicException::class);
        $log->update(['action' => 'diubah']);
    }

    public function test_audit_log_cannot_be_deleted(): void
    {
        $log = Audit::record('test.action');

        $this->expectException(LogicException::class);
        $log->delete();
    }
}
