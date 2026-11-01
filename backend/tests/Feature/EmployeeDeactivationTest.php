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
use Tests\TestCase;

class EmployeeDeactivationTest extends TestCase
{
    use RefreshDatabase;

    private User $hr;
    private User $targetUser;
    private Employee $hrEmployee;
    private Employee $target;

    protected function setUp(): void
    {
        parent::setUp();

        $dept = Department::create(['name' => 'Operasional']);
        $pos = Position::create(['name' => 'Staff']);

        $this->hr = $this->makeUser(Role::Hr, 'hr@t.test');
        $this->targetUser = $this->makeUser(Role::Karyawan, 'kary@t.test');

        $make = fn (string $number, User $user) => Employee::forceCreate([
            'user_id' => $user->id,
            'department_id' => $dept->id,
            'position_id' => $pos->id,
            'employee_number' => $number,
            'full_name' => "Karyawan {$number}",
            'hire_date' => '2024-01-15',
            'employment_status' => EmploymentStatus::Tetap,
            'is_active' => true,
        ]);

        $this->hrEmployee = $make('H1', $this->hr);
        $this->target = $make('T1', $this->targetUser);
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

    private function actAs(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user);
    }

    public function test_deactivating_employee_locks_login_account_and_live_session(): void
    {
        $this->actAs($this->hr)->postJson("/api/employees/{$this->target->id}/deactivate")->assertOk();

        $this->assertFalse($this->target->fresh()->is_active);
        $this->assertFalse($this->targetUser->fresh()->is_active);

        $log = AuditLog::where('action', 'user.updated')
            ->where('auditable_id', $this->targetUser->id)
            ->firstOrFail();
        $this->assertSame($this->hr->id, $log->user_id);

        $this->actAs($this->targetUser->fresh())->getJson('/api/user')->assertUnauthorized();
    }

    public function test_reactivating_employee_does_not_reopen_the_account(): void
    {
        $this->actAs($this->hr)->postJson("/api/employees/{$this->target->id}/deactivate")->assertOk();
        $this->actAs($this->hr)->postJson("/api/employees/{$this->target->id}/activate")->assertOk();

        $this->assertTrue($this->target->fresh()->is_active);
        $this->assertFalse($this->targetUser->fresh()->is_active);
    }

    public function test_hr_cannot_deactivate_own_record(): void
    {
        $this->actAs($this->hr)
            ->postJson("/api/employees/{$this->hrEmployee->id}/deactivate")
            ->assertUnprocessable();

        $this->assertTrue($this->hrEmployee->fresh()->is_active);
        $this->assertTrue($this->hr->fresh()->is_active);
    }
}
