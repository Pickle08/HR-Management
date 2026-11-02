<?php

namespace Tests\Feature;

use App\Enums\EmploymentStatus;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountManagementTest extends TestCase
{
    use RefreshDatabase;

    private Department $dept;
    private Position $pos;
    private User $superUser;
    private User $hr;
    private User $finance;
    private User $atasanUser;
    private User $atasan2User;
    private User $karyawanUser;
    private Employee $atasanEmp;
    private Employee $atasan2Emp;
    private Employee $member;
    private Employee $free;
    private Employee $inactiveFree;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dept = Department::create(['name' => 'Operasional']);
        $this->pos = Position::create(['name' => 'Staff']);

        $this->superUser = $this->makeUser(Role::SuperAdmin, 'super@t.test');
        $this->hr = $this->makeUser(Role::Hr, 'hr@t.test');
        $this->finance = $this->makeUser(Role::Finance, 'fin@t.test');
        $this->atasanUser = $this->makeUser(Role::Atasan, 'atasan@t.test');
        $this->atasan2User = $this->makeUser(Role::Atasan, 'atasan2@t.test');
        $this->karyawanUser = $this->makeUser(Role::Karyawan, 'kary@t.test');

        $this->atasanEmp = $this->makeEmployee('E1', $this->atasanUser);
        $this->atasan2Emp = $this->makeEmployee('E5', $this->atasan2User);
        $this->member = $this->makeEmployee('E2', $this->karyawanUser, $this->atasanEmp);
        $this->free = $this->makeEmployee('E3');
        $this->inactiveFree = $this->makeEmployee('E4', null, null, false);
    }

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

    private function makeEmployee(string $number, ?User $user = null, ?Employee $manager = null, bool $active = true): Employee
    {
        return Employee::forceCreate([
            'user_id' => $user?->id,
            'manager_id' => $manager?->id,
            'department_id' => $this->dept->id,
            'position_id' => $this->pos->id,
            'employee_number' => $number,
            'full_name' => "Karyawan {$number}",
            'hire_date' => '2024-01-15',
            'employment_status' => EmploymentStatus::Tetap,
            'is_active' => $active,
        ]);
    }

    /** Guard Sanctum menyimpan user dari request sebelumnya, jadi di-reset tiap ganti user. */
    private function actAs(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user);
    }

    public function test_only_super_admin_can_manage_accounts(): void
    {
        $id = $this->karyawanUser->id;

        $requests = [
            ['GET', '/api/accounts', []],
            ['POST', '/api/accounts', ['email' => 'x@t.test', 'role' => 'karyawan', 'employee_id' => $this->free->id]],
            ['PUT', "/api/accounts/{$id}/role", ['role' => 'hr']],
            ['POST', "/api/accounts/{$id}/lock", []],
            ['POST', "/api/accounts/{$id}/unlock", []],
            ['POST', "/api/accounts/{$id}/reset-password", []],
        ];

        $this->getJson('/api/accounts')->assertUnauthorized();

        foreach ([$this->hr, $this->finance, $this->atasanUser, $this->karyawanUser] as $user) {
            foreach ($requests as [$method, $url, $body]) {
                $this->actAs($user)->json($method, $url, $body)->assertForbidden();
            }
        }

        $this->assertSame(6, User::count());
        $this->assertSame(Role::Karyawan, $this->karyawanUser->fresh()->role);
        $this->assertTrue($this->karyawanUser->fresh()->is_active);
        $this->assertNull($this->free->fresh()->user_id);
    }

    public function test_create_account_for_employee_returns_one_time_password(): void
    {
        $res = $this->actAs($this->superUser)->postJson('/api/accounts', [
            'email' => 'Baru@Example.com',
            'role' => 'karyawan',
            'employee_id' => $this->free->id,
        ])->assertCreated()
            ->assertJsonPath('data.email', 'baru@example.com')
            ->assertJsonPath('data.role', 'karyawan')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.employee.id', $this->free->id)
            ->assertJsonMissingPath('data.password');

        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));

        $temp = $res->json('meta.temporary_password');
        $user = User::where('email', 'baru@example.com')->firstOrFail();

        $this->assertSame(14, strlen($temp));
        $this->assertTrue(Hash::check($temp, $user->password));
        $this->assertSame($user->id, $this->free->fresh()->user_id);

        $log = AuditLog::where('action', 'user.created')->where('auditable_id', $user->id)->firstOrFail();

        $this->assertSame($this->superUser->id, $log->user_id);
        $this->assertNotSame($temp, $log->new_values['password'] ?? null);
        $this->assertNotSame($user->password, $log->new_values['password'] ?? null);
    }

    public function test_create_account_rules(): void
    {
        $before = User::count();

        $bad = [
            [['email' => 'a@t.test', 'role' => 'karyawan', 'employee_id' => $this->atasanEmp->id], 'employee_id'],
            [['email' => 'b@t.test', 'role' => 'karyawan', 'employee_id' => $this->inactiveFree->id], 'employee_id'],
            [['email' => 'hr@t.test', 'role' => 'karyawan', 'employee_id' => $this->free->id], 'email'],
            [['email' => 'HR@T.TEST', 'role' => 'karyawan', 'employee_id' => $this->free->id], 'email'],
            [['email' => 'c@t.test', 'role' => 'hr'], 'employee_id'],
            [['email' => 'd@t.test', 'role' => 'bos', 'employee_id' => $this->free->id], 'role'],
            [['email' => 'e@t.test', 'role' => 'super_admin'], 'name'],
        ];

        foreach ($bad as [$payload, $field]) {
            $this->actAs($this->superUser)->postJson('/api/accounts', $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        $this->assertSame($before, User::count());
        $this->assertNull($this->free->fresh()->user_id);

        $this->actAs($this->superUser)->postJson('/api/accounts', [
            'email' => 'root2@t.test',
            'role' => 'super_admin',
            'name' => 'Admin Kedua',
        ])->assertCreated()->assertJsonPath('data.employee', null);

        $this->assertSame($before + 1, User::count());
    }

    public function test_change_role_is_audited_and_protects_teams(): void
    {
        // Atasan tanpa bawahan boleh pindah peran.
        $this->actAs($this->superUser)
            ->putJson("/api/accounts/{$this->atasan2User->id}/role", ['role' => 'hr'])
            ->assertOk()
            ->assertJsonPath('data.role', 'hr');

        $log = AuditLog::where('action', 'user.updated')->where('auditable_id', $this->atasan2User->id)->firstOrFail();

        $this->assertSame($this->superUser->id, $log->user_id);
        $this->assertSame('atasan', $log->old_values['role']);
        $this->assertSame('hr', $log->new_values['role']);

        // Atasan yang masih punya bawahan aktif ditolak.
        $this->actAs($this->superUser)
            ->putJson("/api/accounts/{$this->atasanUser->id}/role", ['role' => 'karyawan'])
            ->assertStatus(409);

        $this->assertSame(Role::Atasan, $this->atasanUser->fresh()->role);

        // Akun sendiri.
        $this->actAs($this->superUser)
            ->putJson("/api/accounts/{$this->superUser->id}/role", ['role' => 'hr'])
            ->assertUnprocessable();

        $this->assertSame(Role::SuperAdmin, $this->superUser->fresh()->role);

        // Peran non-Super Admin butuh data karyawan; peran tidak dikenal ditolak.
        $noEmployee = $this->makeUser(Role::Karyawan, 'noemp@t.test');

        $this->actAs($this->superUser)
            ->putJson("/api/accounts/{$noEmployee->id}/role", ['role' => 'hr'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->actAs($this->superUser)
            ->putJson("/api/accounts/{$noEmployee->id}/role", ['role' => 'bos'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        // Setelah bawahan dipindah, atasan itu boleh berganti peran.
        $this->member->forceFill(['manager_id' => null])->save();

        $this->actAs($this->superUser)
            ->putJson("/api/accounts/{$this->atasanUser->id}/role", ['role' => 'karyawan'])
            ->assertOk();

        $this->assertSame(Role::Karyawan, $this->atasanUser->fresh()->role);
    }

    public function test_lock_and_unlock_rules(): void
    {
        $this->karyawanUser->createToken('t');
        $id = $this->karyawanUser->id;

        $this->actAs($this->superUser)->postJson("/api/accounts/{$id}/lock")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse($this->karyawanUser->fresh()->is_active);
        $this->assertSame(0, $this->karyawanUser->tokens()->count());

        // Atasan dengan bawahan aktif dan akun sendiri tidak bisa dikunci.
        $this->actAs($this->superUser)->postJson("/api/accounts/{$this->atasanUser->id}/lock")->assertStatus(409);
        $this->actAs($this->superUser)->postJson("/api/accounts/{$this->superUser->id}/lock")->assertUnprocessable();

        $this->assertTrue($this->atasanUser->fresh()->is_active);
        $this->assertTrue($this->superUser->fresh()->is_active);

        // Akun karyawan nonaktif tidak boleh dibuka.
        $this->member->forceFill(['is_active' => false])->save();

        $this->actAs($this->superUser)->postJson("/api/accounts/{$id}/unlock")->assertStatus(409);
        $this->assertFalse($this->karyawanUser->fresh()->is_active);

        $this->member->forceFill(['is_active' => true])->save();

        $this->actAs($this->superUser)->postJson("/api/accounts/{$id}/unlock")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->assertTrue($this->karyawanUser->fresh()->is_active);
    }

    public function test_reset_password_returns_new_password_once_and_audits(): void
    {
        $this->karyawanUser->createToken('t');

        $res = $this->actAs($this->superUser)
            ->postJson("/api/accounts/{$this->karyawanUser->id}/reset-password")
            ->assertOk()
            ->assertJsonStructure(['data' => ['id', 'email'], 'meta' => ['temporary_password']]);

        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));

        $temp = $res->json('meta.temporary_password');
        $fresh = $this->karyawanUser->fresh();

        $this->assertSame(14, strlen($temp));
        $this->assertTrue(Hash::check($temp, $fresh->password));
        $this->assertFalse(Hash::check('password', $fresh->password));
        $this->assertSame(0, $fresh->tokens()->count());

        $log = AuditLog::where('action', 'account.password_reset')->where('auditable_id', $fresh->id)->firstOrFail();
        $this->assertSame($this->superUser->id, $log->user_id);

        foreach (AuditLog::all() as $row) {
            $this->assertStringNotContainsString($temp, json_encode([$row->old_values, $row->new_values]));
        }

        $this->actAs($this->superUser)
            ->postJson("/api/accounts/{$this->superUser->id}/reset-password")
            ->assertUnprocessable();
    }

    public function test_user_changes_own_password(): void
    {
        $this->putJson('/api/user/password', [])->assertUnauthorized();

        $ok = [
            'current_password' => 'password',
            'password' => 'PasswordBaru2030',
            'password_confirmation' => 'PasswordBaru2030',
        ];

        $this->actAs($this->karyawanUser)
            ->putJson('/api/user/password', array_merge($ok, ['current_password' => 'salah']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->actAs($this->karyawanUser)
            ->putJson('/api/user/password', array_merge($ok, ['password' => 'pendek1', 'password_confirmation' => 'pendek1']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->actAs($this->karyawanUser)
            ->putJson('/api/user/password', array_merge($ok, ['password_confirmation' => 'Berbeda12345']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check('password', $this->karyawanUser->fresh()->password));

        $this->actAs($this->karyawanUser)->putJson('/api/user/password', $ok)->assertNoContent();

        $this->assertTrue(Hash::check('PasswordBaru2030', $this->karyawanUser->fresh()->password));
    }

    public function test_deactivating_employee_with_active_team_is_refused(): void
    {
        $this->actAs($this->hr)->postJson("/api/employees/{$this->atasanEmp->id}/deactivate")->assertStatus(409);

        $this->assertTrue($this->atasanEmp->fresh()->is_active);
        $this->assertTrue($this->atasanUser->fresh()->is_active);

        $this->actAs($this->hr)->postJson("/api/employees/{$this->member->id}/deactivate")->assertOk();
        $this->actAs($this->hr)->postJson("/api/employees/{$this->atasanEmp->id}/deactivate")->assertOk();

        $this->assertFalse($this->atasanUser->fresh()->is_active);
    }

    public function test_account_list_filters_and_hides_secrets(): void
    {
        $this->karyawanUser->forceFill(['is_active' => false])->save();

        $this->actAs($this->superUser)->getJson('/api/accounts')
            ->assertOk()
            ->assertJsonCount(6, 'data')
            ->assertJsonMissingPath('data.0.password');

        $this->actAs($this->superUser)->getJson('/api/accounts?role=atasan')->assertOk()->assertJsonCount(2, 'data');
        $this->actAs($this->superUser)->getJson('/api/accounts?is_active=0')->assertOk()->assertJsonCount(1, 'data');
        $this->actAs($this->superUser)->getJson('/api/accounts?is_active=1')->assertOk()->assertJsonCount(5, 'data');
        $this->actAs($this->superUser)->getJson('/api/accounts?search=kary')->assertOk()->assertJsonCount(1, 'data');
        $this->actAs($this->superUser)->getJson('/api/accounts?role=bos')->assertUnprocessable();
    }
}
