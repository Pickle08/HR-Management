<?php

namespace Tests\Feature;

use App\Enums\EmploymentStatus;
use App\Enums\LeaveStatus;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LeaveRequestTest extends TestCase
{
    use RefreshDatabase;

    private Department $dept;
    private Position $pos;
    private User $hr;
    private User $finance;
    private User $superUser;
    private User $atasanUser;
    private User $karyawanUser;
    private User $atasan2User;
    private Employee $super;    // tanpa atasan
    private Employee $atasan;   // tanpa atasan
    private Employee $member;   // bawahan atasan
    private Employee $atasan2;  // atasan lain
    private LeaveType $annual;

    protected function setUp(): void
    {
        parent::setUp();

        // Kunci waktu: 1 Feb 2030 (Jumat). 4 Mar 2030 = Senin.
        $this->travelTo(Carbon::parse('2030-02-01 09:00:00'));

        $this->dept = Department::create(['name' => 'Operasional']);
        $this->pos = Position::create(['name' => 'Staff']);

        $this->hr = $this->makeUser(Role::Hr, 'hr@t.test');
        $this->finance = $this->makeUser(Role::Finance, 'fin@t.test');
        $this->superUser = $this->makeUser(Role::SuperAdmin, 'super@t.test');
        $this->atasanUser = $this->makeUser(Role::Atasan, 'atasan@t.test');
        $this->karyawanUser = $this->makeUser(Role::Karyawan, 'kary@t.test');
        $this->atasan2User = $this->makeUser(Role::Atasan, 'atasan2@t.test');

        $this->super = $this->makeEmployee('S1', $this->superUser);
        $this->atasan = $this->makeEmployee('E1', $this->atasanUser);
        $this->member = $this->makeEmployee('E2', $this->karyawanUser, $this->atasan);
        $this->atasan2 = $this->makeEmployee('E4', $this->atasan2User);

        $this->annual = LeaveType::create(['name' => 'Cuti Tahunan', 'annual_quota' => 12, 'is_active' => true]);

        foreach ([$this->super, $this->atasan, $this->member] as $employee) {
            LeaveBalance::forceCreate([
                'employee_id' => $employee->id,
                'leave_type_id' => $this->annual->id,
                'year' => 2030,
                'entitled_days' => 12,
            ]);
        }
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

    private function makeLeave(Employee $employee, LeaveStatus $status = LeaveStatus::Pending): LeaveRequest
    {
        return LeaveRequest::forceCreate([
            'employee_id' => $employee->id,
            'leave_type_id' => $this->annual->id,
            'year' => 2030,
            'start_date' => '2030-03-04',
            'end_date' => '2030-03-06',
            'days' => 3,
            'status' => $status,
        ]);
    }

    /** Guard Sanctum menyimpan user dari request sebelumnya, jadi di-reset tiap ganti user. */
    private function actAs(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user);
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'leave_type_id' => $this->annual->id,
            'start_date' => '2030-03-04', // Senin
            'end_date' => '2030-03-06',   // Rabu -> 3 hari kerja
            'reason' => 'Keperluan keluarga',
        ], $override);
    }

    private function submitAs(User $user, array $override = [])
    {
        return $this->actAs($user)->postJson('/api/leave-requests', $this->payload($override));
    }

    private function approve(User $user, int $id, ?string $note = null)
    {
        return $this->actAs($user)->postJson("/api/leave-requests/{$id}/approve", $note ? ['note' => $note] : []);
    }

    private function employeeIds($response): array
    {
        return collect($response->json('data'))->pluck('employee.id')->all();
    }

    public function test_employee_can_submit_and_weekends_are_not_counted(): void
    {
        // Senin 4 Mar sampai Senin 11 Mar: Sen-Jum + Sen = 6 hari kerja.
        $this->submitAs($this->karyawanUser, ['end_date' => '2030-03-11'])
            ->assertCreated()
            ->assertJsonPath('data.days', 6)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.year', 2030)
            ->assertJsonPath('data.employee.id', $this->member->id);

        $log = AuditLog::where('action', 'leaverequest.created')->firstOrFail();
        $this->assertSame($this->karyawanUser->id, $log->user_id);
    }

    public function test_client_cannot_force_server_controlled_fields(): void
    {
        $this->submitAs($this->karyawanUser, [
            'employee_id' => $this->atasan->id,
            'status' => 'approved',
            'days' => 99,
            'year' => 1999,
            'decided_by' => $this->atasanUser->id,
        ])->assertCreated();

        $leave = LeaveRequest::firstOrFail();

        $this->assertSame($this->member->id, $leave->employee_id);
        $this->assertSame('pending', $leave->status->value);
        $this->assertSame(3, $leave->days);
        $this->assertSame(2030, $leave->year);
        $this->assertNull($leave->decided_by);
    }

    public function test_weekend_only_range_is_rejected(): void
    {
        $this->submitAs($this->karyawanUser, ['start_date' => '2030-03-09', 'end_date' => '2030-03-10'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('start_date');
    }

    public function test_range_crossing_year_boundary_is_rejected(): void
    {
        $this->submitAs($this->karyawanUser, ['start_date' => '2030-12-30', 'end_date' => '2031-01-02'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('end_date');
    }

    public function test_start_date_in_the_past_is_rejected(): void
    {
        $this->submitAs($this->karyawanUser, ['start_date' => '2030-01-30', 'end_date' => '2030-01-31'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('start_date');
    }

    public function test_submission_requires_a_balance_for_that_type_and_year(): void
    {
        $sick = LeaveType::create(['name' => 'Cuti Sakit', 'annual_quota' => 14, 'is_active' => true]);

        $this->submitAs($this->karyawanUser, ['leave_type_id' => $sick->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('leave_type_id');

        $this->assertSame(0, LeaveRequest::count());
    }

    public function test_pending_requests_already_reserve_the_balance(): void
    {
        // 6 hari (pending, belum disetujui) + 9 hari = 15 > jatah 12.
        $this->submitAs($this->karyawanUser, ['end_date' => '2030-03-11'])->assertCreated();

        $this->submitAs($this->karyawanUser, ['start_date' => '2030-03-12', 'end_date' => '2030-03-22'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('leave_type_id');

        $this->assertSame(1, LeaveRequest::count());
    }

    public function test_overlapping_requests_are_rejected(): void
    {
        $this->submitAs($this->karyawanUser)->assertCreated();

        $this->submitAs($this->karyawanUser, ['start_date' => '2030-03-06', 'end_date' => '2030-03-08'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('start_date');
    }

    public function test_rejected_request_frees_dates_and_balance(): void
    {
        $id = $this->submitAs($this->karyawanUser)->assertCreated()->json('data.id');

        $this->actAs($this->atasanUser)
            ->postJson("/api/leave-requests/{$id}/reject", ['note' => 'Tim sedang padat'])
            ->assertOk();

        $this->submitAs($this->karyawanUser)->assertCreated();

        $this->assertSame(2, LeaveRequest::count());
    }

    public function test_only_active_employee_with_profile_can_submit(): void
    {
        // Finance di fixture ini tidak punya data karyawan.
        $this->submitAs($this->finance)->assertForbidden();

        $this->member->forceFill(['is_active' => false])->save();
        $this->submitAs($this->karyawanUser)->assertForbidden();

        $this->assertSame(0, LeaveRequest::count());
    }

    public function test_direct_manager_can_approve_and_decision_is_recorded(): void
    {
        $id = $this->submitAs($this->karyawanUser)->assertCreated()->json('data.id');

        $this->approve($this->atasanUser, $id, 'Silakan')
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.decision.by.id', $this->atasanUser->id)
            ->assertJsonPath('data.decision.note', 'Silakan');

        $leave = LeaveRequest::findOrFail($id);
        $this->assertSame($this->atasanUser->id, $leave->decided_by);
        $this->assertNotNull($leave->decided_at);

        $log = AuditLog::where('action', 'leaverequest.updated')->where('auditable_id', $id)->firstOrFail();
        $this->assertSame($this->atasanUser->id, $log->user_id);
        $this->assertSame('pending', $log->old_values['status']);
        $this->assertSame('approved', $log->new_values['status']);
    }

    public function test_a_decision_cannot_be_overwritten(): void
    {
        $id = $this->submitAs($this->karyawanUser)->assertCreated()->json('data.id');
        $this->approve($this->atasanUser, $id)->assertOk();

        $this->actAs($this->atasanUser)
            ->postJson("/api/leave-requests/{$id}/reject", ['note' => 'Berubah pikiran'])
            ->assertStatus(409);

        $this->approve($this->atasanUser, $id)->assertStatus(409);

        $leave = LeaveRequest::findOrFail($id);
        $this->assertSame('approved', $leave->status->value);
        $this->assertSame($this->atasanUser->id, $leave->decided_by);
    }

    public function test_rejection_requires_a_note(): void
    {
        $id = $this->submitAs($this->karyawanUser)->assertCreated()->json('data.id');

        $this->actAs($this->atasanUser)
            ->postJson("/api/leave-requests/{$id}/reject")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('note');

        $this->assertSame('pending', LeaveRequest::findOrFail($id)->status->value);

        $this->actAs($this->atasanUser)
            ->postJson("/api/leave-requests/{$id}/reject", ['note' => 'Tim sedang padat'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');
    }

    public function test_only_the_direct_manager_can_decide(): void
    {
        $id = $this->submitAs($this->karyawanUser)->assertCreated()->json('data.id');

        $others = [$this->atasan2User, $this->hr, $this->karyawanUser, $this->finance, $this->superUser];

        foreach ($others as $user) {
            $this->approve($user, $id)->assertForbidden();
        }

        $this->assertSame('pending', LeaveRequest::findOrFail($id)->status->value);
    }

    public function test_super_admin_decides_for_managerless_employees_but_never_own_request(): void
    {
        // E1 (atasan) tidak punya atasan: diputuskan Super Admin.
        $id = $this->submitAs($this->atasanUser)->assertCreated()->json('data.id');

        $this->approve($this->atasanUser, $id)->assertForbidden(); // diri sendiri
        $this->approve($this->hr, $id)->assertForbidden();
        $this->approve($this->atasan2User, $id)->assertForbidden();
        $this->approve($this->superUser, $id)->assertOk()->assertJsonPath('data.status', 'approved');

        // Super Admin sendiri juga tidak bisa menyetujui pengajuannya sendiri.
        $ownId = $this->submitAs($this->superUser)->assertCreated()->json('data.id');
        $this->approve($this->superUser, $ownId)->assertForbidden();
    }

    public function test_approval_rechecks_balance_when_hr_lowers_entitlement(): void
    {
        $id = $this->submitAs($this->karyawanUser)->assertCreated()->json('data.id'); // 3 hari

        LeaveBalance::query()->where('employee_id', $this->member->id)->update(['entitled_days' => 2]);

        $this->approve($this->atasanUser, $id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('leave_type_id');

        $this->assertSame('pending', LeaveRequest::findOrFail($id)->status->value);
    }

    public function test_owner_can_cancel_pending_but_not_approved_and_others_cannot_cancel(): void
    {
        $id = $this->submitAs($this->karyawanUser)->assertCreated()->json('data.id');

        $this->actAs($this->atasanUser)->postJson("/api/leave-requests/{$id}/cancel")->assertForbidden();
        $this->actAs($this->atasan2User)->postJson("/api/leave-requests/{$id}/cancel")->assertForbidden();

        $this->actAs($this->karyawanUser)->postJson("/api/leave-requests/{$id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        // Pengajuan baru di tanggal yang sama, disetujui: pemilik tidak bisa membatalkannya sendiri.
        $id2 = $this->submitAs($this->karyawanUser)->assertCreated()->json('data.id');
        $this->approve($this->atasanUser, $id2)->assertOk();

        $this->actAs($this->karyawanUser)->postJson("/api/leave-requests/{$id2}/cancel")->assertStatus(409);
        $this->assertSame('approved', LeaveRequest::findOrFail($id2)->status->value);
    }

    public function test_hr_can_cancel_approved_leave_and_approval_history_is_preserved(): void
    {
        $id = $this->submitAs($this->karyawanUser)->assertCreated()->json('data.id');
        $this->approve($this->atasanUser, $id, 'Silakan')->assertOk();

        $this->actAs($this->hr)->postJson("/api/leave-requests/{$id}/cancel")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('note');

        $this->actAs($this->hr)
            ->postJson("/api/leave-requests/{$id}/cancel", ['note' => 'Dibatalkan karena proyek mendesak'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancellation.by.id', $this->hr->id);

        $leave = LeaveRequest::findOrFail($id);

        // Riwayat persetujuan tidak tertimpa.
        $this->assertSame($this->atasanUser->id, $leave->decided_by);
        $this->assertNotNull($leave->decided_at);
        $this->assertSame('Silakan', $leave->decision_note);

        $this->assertSame($this->hr->id, $leave->cancelled_by);
        $this->assertNotNull($leave->cancelled_at);
    }

    public function test_listing_is_scoped_and_other_teams_requests_are_forbidden(): void
    {
        $memberLeave = $this->makeLeave($this->member);
        $atasanLeave = $this->makeLeave($this->atasan);
        $this->makeLeave($this->atasan2);

        $this->getJson('/api/leave-requests')->assertUnauthorized();

        $this->actAs($this->hr)->getJson('/api/leave-requests')->assertOk()->assertJsonCount(3, 'data');

        $res = $this->actAs($this->atasanUser)->getJson('/api/leave-requests')->assertOk();
        $this->assertEqualsCanonicalizing([$this->atasan->id, $this->member->id], $this->employeeIds($res));

        $res = $this->actAs($this->karyawanUser)->getJson('/api/leave-requests')->assertOk();
        $this->assertSame([$this->member->id], $this->employeeIds($res));

        $res = $this->actAs($this->atasan2User)->getJson('/api/leave-requests')->assertOk();
        $this->assertSame([$this->atasan2->id], $this->employeeIds($res));

        $this->actAs($this->finance)->getJson('/api/leave-requests')->assertForbidden();

        // IDOR: ganti ID di URL.
        $this->actAs($this->atasan2User)->getJson("/api/leave-requests/{$memberLeave->id}")->assertForbidden();
        $this->actAs($this->karyawanUser)->getJson("/api/leave-requests/{$atasanLeave->id}")->assertForbidden();
        $this->actAs($this->karyawanUser)->getJson("/api/leave-requests/{$memberLeave->id}")->assertOk();
    }

    public function test_balance_endpoint_shows_used_pending_and_remaining(): void
    {
        $first = $this->submitAs($this->karyawanUser)->assertCreated()->json('data.id'); // 4-6 Mar, 3 hari
        $this->approve($this->atasanUser, $first)->assertOk();

        $this->submitAs($this->karyawanUser, ['start_date' => '2030-03-11', 'end_date' => '2030-03-13'])
            ->assertCreated(); // 3 hari, pending

        $this->actAs($this->karyawanUser)->getJson('/api/leave-balances')
            ->assertOk()
            ->assertJsonPath('data.0.entitled_days', 12)
            ->assertJsonPath('data.0.used_days', 3)
            ->assertJsonPath('data.0.pending_days', 3)
            ->assertJsonPath('data.0.remaining_days', 6);

        // HR membatalkan yang sudah disetujui: jatah kembali.
        $this->actAs($this->hr)
            ->postJson("/api/leave-requests/{$first}/cancel", ['note' => 'Dibatalkan'])
            ->assertOk();

        $this->actAs($this->karyawanUser)->getJson('/api/leave-balances')
            ->assertOk()
            ->assertJsonPath('data.0.used_days', 0)
            ->assertJsonPath('data.0.pending_days', 3)
            ->assertJsonPath('data.0.remaining_days', 9);
    }
}
