<?php

namespace Tests\Feature;

use App\Enums\EmploymentStatus;
use App\Enums\LeaveStatus;
use App\Enums\Role;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveAbilitiesTest extends TestCase
{
    use RefreshDatabase;

    private User $hr;
    private User $superUser;
    private User $atasanUser;
    private User $memberUser;
    private User $outsiderUser;
    private Employee $atasanEmp;
    private Employee $member;
    private Employee $outsider;
    private Department $dept;
    private Position $pos;
    private LeaveType $annual;
    private LeaveRequest $memberPending;
    private LeaveRequest $memberApproved;
    private LeaveRequest $memberRejected;
    private LeaveRequest $outsiderPending;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dept = Department::create(['name' => 'Operasional']);
        $this->pos = Position::create(['name' => 'Staff']);
        $this->annual = LeaveType::create(['name' => 'Cuti Tahunan', 'annual_quota' => 12, 'is_active' => true]);

        $this->hr = $this->makeUser(Role::Hr, 'hr@t.test');
        $this->superUser = $this->makeUser(Role::SuperAdmin, 'super@t.test');
        $this->atasanUser = $this->makeUser(Role::Atasan, 'atasan@t.test');
        $this->memberUser = $this->makeUser(Role::Karyawan, 'member@t.test');
        $this->outsiderUser = $this->makeUser(Role::Karyawan, 'outsider@t.test');

        $this->atasanEmp = $this->makeEmployee('E1', $this->atasanUser);
        $this->member = $this->makeEmployee('E2', $this->memberUser, $this->atasanEmp);
        $this->outsider = $this->makeEmployee('E3', $this->outsiderUser);

        $this->memberPending = $this->makeLeave($this->member, LeaveStatus::Pending);
        $this->memberApproved = $this->makeLeave($this->member, LeaveStatus::Approved);
        $this->memberRejected = $this->makeLeave($this->member, LeaveStatus::Rejected);
        $this->outsiderPending = $this->makeLeave($this->outsider, LeaveStatus::Pending);
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

    private function makeLeave(Employee $employee, LeaveStatus $status): LeaveRequest
    {
        return LeaveRequest::forceCreate([
            'employee_id' => $employee->id,
            'leave_type_id' => $this->annual->id,
            'year' => 2030,
            'start_date' => '2030-03-20',
            'end_date' => '2030-03-22',
            'days' => 3,
            'reason' => 'Keperluan pribadi',
            'status' => $status,
        ]);
    }

    /** Guard Sanctum menyimpan user dari request sebelumnya, jadi di-reset tiap ganti user. */
    private function actAs(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user);
    }

    /** @return array{0: bool, 1: bool} [can_decide, can_cancel] */
    private function flags(User $user, LeaveRequest $leave): array
    {
        $data = $this->actAs($user)->getJson("/api/leave-requests/{$leave->id}")->assertOk()->json('data');

        return [$data['can_decide'], $data['can_cancel']];
    }

    public function test_pending_request_with_a_manager(): void
    {
        $leave = $this->memberPending;

        $this->assertSame([true, false], $this->flags($this->atasanUser, $leave));
        $this->assertSame([false, true], $this->flags($this->memberUser, $leave));
        $this->assertSame([false, true], $this->flags($this->hr, $leave));

        // Super Admin hanya memutuskan karyawan tanpa atasan, dan atasan ada di sini.
        $this->assertSame([false, true], $this->flags($this->superUser, $leave));
    }

    public function test_pending_request_without_a_manager_goes_to_super_admin(): void
    {
        $leave = $this->outsiderPending;

        $this->assertSame([true, true], $this->flags($this->superUser, $leave));
        $this->assertSame([false, true], $this->flags($this->hr, $leave));
        $this->assertSame([false, true], $this->flags($this->outsiderUser, $leave));

        // Atasan di luar rantai tidak bisa melihatnya sama sekali.
        $this->actAs($this->atasanUser)->getJson("/api/leave-requests/{$leave->id}")->assertForbidden();
    }

    public function test_decided_requests_only_allow_hr_cancellation_of_approved(): void
    {
        $approved = $this->memberApproved;

        $this->assertSame([false, false], $this->flags($this->memberUser, $approved));
        $this->assertSame([false, false], $this->flags($this->atasanUser, $approved));
        $this->assertSame([false, true], $this->flags($this->hr, $approved));
        $this->assertSame([false, true], $this->flags($this->superUser, $approved));

        $rejected = $this->memberRejected;

        foreach ([$this->memberUser, $this->atasanUser, $this->hr, $this->superUser] as $user) {
            $this->assertSame([false, false], $this->flags($user, $rejected));
        }
    }

    public function test_list_uses_the_same_flags_and_has_manager_data_for_the_policy(): void
    {
        $res = $this->actAs($this->atasanUser)->getJson('/api/leave-requests')->assertOk();

        $byId = collect($res->json('data'))->keyBy('id');

        $this->assertTrue($byId[$this->memberPending->id]['can_decide']);
        $this->assertFalse($byId[$this->memberApproved->id]['can_decide']);
        $this->assertFalse($byId[$this->memberPending->id]['can_cancel']);
        $this->assertFalse($byId->has($this->outsiderPending->id));
    }
}
