<?php

namespace Tests\Feature;

use App\Enums\EmploymentStatus;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveSetupTest extends TestCase
{
    use RefreshDatabase;

    private Department $dept;
    private Position $pos;
    private User $hr;
    private User $finance;
    private User $atasanUser;
    private User $karyawanUser;
    private Employee $atasan;
    private Employee $member;
    private Employee $outsider;
    private LeaveType $annual;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dept = Department::create(['name' => 'Operasional']);
        $this->pos = Position::create(['name' => 'Staff']);

        $this->hr = $this->makeUser(Role::Hr, 'hr@t.test');
        $this->finance = $this->makeUser(Role::Finance, 'fin@t.test');
        $this->atasanUser = $this->makeUser(Role::Atasan, 'atasan@t.test');
        $this->karyawanUser = $this->makeUser(Role::Karyawan, 'kary@t.test');

        $this->atasan = $this->makeEmployee('E1', $this->atasanUser);
        $this->member = $this->makeEmployee('E2', $this->karyawanUser, $this->atasan);
        $this->outsider = $this->makeEmployee('E3');

        $this->annual = LeaveType::create(['name' => 'Cuti Tahunan', 'annual_quota' => 12, 'is_active' => true]);
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

    private function makeEmployee(string $number, ?User $user = null, ?Employee $manager = null): Employee
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
            'is_active' => true,
        ]);
    }

    private function makeBalance(Employee $employee, ?int $year = null): LeaveBalance
    {
        return LeaveBalance::forceCreate([
            'employee_id' => $employee->id,
            'leave_type_id' => $this->annual->id,
            'year' => $year ?? now()->year,
            'entitled_days' => 12,
        ]);
    }

    /** Guard Sanctum menyimpan user dari request sebelumnya, jadi di-reset tiap ganti user. */
    private function actAs(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user);
    }

    private function employeeIds($response): array
    {
        return collect($response->json('data'))->pluck('employee.id')->all();
    }

    public function test_every_role_can_list_active_types_but_only_hr_sees_inactive(): void
    {
        LeaveType::create(['name' => 'Cuti Lama', 'annual_quota' => 5, 'is_active' => false]);

        foreach ([$this->karyawanUser, $this->atasanUser, $this->finance] as $user) {
            $this->actAs($user)->getJson('/api/leave-types')->assertOk()->assertJsonCount(1, 'data');
        }

        $this->actAs($this->hr)->getJson('/api/leave-types')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_hr_can_create_leave_type_and_others_cannot(): void
    {
        $payload = ['name' => 'Cuti Melahirkan', 'annual_quota' => 90];

        foreach ([$this->atasanUser, $this->karyawanUser, $this->finance] as $user) {
            $this->actAs($user)->postJson('/api/leave-types', $payload)->assertForbidden();
        }

        $this->actAs($this->hr)->postJson('/api/leave-types', $payload)
            ->assertCreated()
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.annual_quota', 90);
    }

    public function test_duplicate_leave_type_name_is_rejected(): void
    {
        $this->actAs($this->hr)
            ->postJson('/api/leave-types', ['name' => 'Cuti Tahunan', 'annual_quota' => 5])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_leave_type_change_is_audited_with_old_and_new_values(): void
    {
        $this->actAs($this->hr)->putJson("/api/leave-types/{$this->annual->id}", [
            'name' => 'Cuti Tahunan',
            'annual_quota' => 15,
        ])->assertOk();

        $log = AuditLog::where('action', 'leavetype.updated')->firstOrFail();

        $this->assertSame($this->hr->id, $log->user_id);
        $this->assertEquals(12, $log->old_values['annual_quota']);
        $this->assertEquals(15, $log->new_values['annual_quota']);
    }

    public function test_generate_balances_is_idempotent_and_skips_inactive(): void
    {
        LeaveType::create(['name' => 'Cuti Sakit', 'annual_quota' => 14, 'is_active' => true]);
        LeaveType::create(['name' => 'Cuti Lama', 'annual_quota' => 5, 'is_active' => false]);
        $this->outsider->forceFill(['is_active' => false])->save();

        $year = now()->year;

        // 2 karyawan aktif x 2 jenis aktif = 4
        $this->actAs($this->hr)->postJson('/api/leave-balances/generate', ['year' => $year])
            ->assertOk()
            ->assertJsonPath('created', 4);

        $this->actAs($this->hr)->postJson('/api/leave-balances/generate', ['year' => $year])
            ->assertOk()
            ->assertJsonPath('created', 0);

        $this->assertSame(4, LeaveBalance::count());
        $this->assertSame(
            12,
            LeaveBalance::where('leave_type_id', $this->annual->id)->firstOrFail()->entitled_days
        );
    }

    public function test_non_hr_cannot_generate_balances(): void
    {
        foreach ([$this->atasanUser, $this->karyawanUser, $this->finance] as $user) {
            $this->actAs($user)
                ->postJson('/api/leave-balances/generate', ['year' => now()->year])
                ->assertForbidden();
        }

        $this->assertSame(0, LeaveBalance::count());
    }

    public function test_hr_can_set_individual_balance_and_change_is_audited(): void
    {
        $payload = [
            'employee_id' => $this->member->id,
            'leave_type_id' => $this->annual->id,
            'year' => now()->year,
            'entitled_days' => 12,
        ];

        $this->actAs($this->hr)->putJson('/api/leave-balances', $payload)
            ->assertCreated()
            ->assertJsonPath('data.entitled_days', 12);

        $this->actAs($this->hr)->putJson('/api/leave-balances', array_merge($payload, ['entitled_days' => 15]))
            ->assertOk()
            ->assertJsonPath('data.entitled_days', 15);

        $this->assertSame(1, LeaveBalance::count());

        $log = AuditLog::where('action', 'leavebalance.updated')->firstOrFail();

        $this->assertSame($this->hr->id, $log->user_id);
        $this->assertEquals(12, $log->old_values['entitled_days']);
        $this->assertEquals(15, $log->new_values['entitled_days']);
    }

    public function test_set_balance_validates_input_and_requires_hr(): void
    {
        $base = [
            'employee_id' => $this->member->id,
            'leave_type_id' => $this->annual->id,
            'year' => now()->year,
            'entitled_days' => 12,
        ];

        foreach ([['entitled_days' => 400], ['year' => 1999], ['employee_id' => 99999], ['leave_type_id' => 99999]] as $bad) {
            $this->actAs($this->hr)
                ->putJson('/api/leave-balances', array_merge($base, $bad))
                ->assertUnprocessable();
        }

        foreach ([$this->atasanUser, $this->karyawanUser, $this->finance] as $user) {
            $this->actAs($user)->putJson('/api/leave-balances', $base)->assertForbidden();
        }

        $this->assertSame(0, LeaveBalance::count());
    }

    public function test_balance_list_is_scoped_by_role(): void
    {
        foreach ([$this->atasan, $this->member, $this->outsider] as $employee) {
            $this->makeBalance($employee);
        }

        $this->getJson('/api/leave-balances')->assertUnauthorized();

        $this->actAs($this->hr)->getJson('/api/leave-balances')->assertOk()->assertJsonCount(3, 'data');

        $res = $this->actAs($this->atasanUser)->getJson('/api/leave-balances')->assertOk();
        $this->assertEqualsCanonicalizing([$this->atasan->id, $this->member->id], $this->employeeIds($res));

        $res = $this->actAs($this->karyawanUser)->getJson('/api/leave-balances')->assertOk();
        $this->assertSame([$this->member->id], $this->employeeIds($res));

        $this->actAs($this->finance)->getJson('/api/leave-balances')->assertForbidden();
    }

    public function test_balance_list_defaults_to_current_year_and_accepts_year_filter(): void
    {
        $this->makeBalance($this->member);
        $this->makeBalance($this->member, now()->year - 1);

        $this->actAs($this->karyawanUser)->getJson('/api/leave-balances')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.year', now()->year);

        $this->actAs($this->karyawanUser)->getJson('/api/leave-balances?year='.(now()->year - 1))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.year', now()->year - 1);
    }
}
