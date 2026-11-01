<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\EmploymentStatus;
use App\Enums\Role;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    private Department $dept;
    private Position $pos;
    private User $hr;
    private User $finance;
    private User $atasanUser;
    private User $karyawanUser;
    private Employee $atasan;
    private Employee $member;    // bawahan atasan, punya akun karyawan
    private Employee $member2;   // bawahan atasan, tanpa akun
    private Employee $outsider;  // tim lain
    private string $day;

    protected function setUp(): void
    {
        parent::setUp();

        $this->day = now()->subDay()->toDateString();
        $this->dept = Department::create(['name' => 'Operasional']);
        $this->pos = Position::create(['name' => 'Staff']);

        $this->hr = $this->makeUser(Role::Hr, 'hr@t.test');
        $this->finance = $this->makeUser(Role::Finance, 'fin@t.test');
        $this->atasanUser = $this->makeUser(Role::Atasan, 'atasan@t.test');
        $this->karyawanUser = $this->makeUser(Role::Karyawan, 'kary@t.test');

        $this->atasan = $this->makeEmployee('E1', $this->atasanUser);
        $this->member = $this->makeEmployee('E2', $this->karyawanUser, $this->atasan);
        $this->member2 = $this->makeEmployee('E3', null, $this->atasan);
        $this->outsider = $this->makeEmployee('E4');
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

    private function makeAttendance(
        Employee $employee,
        AttendanceStatus $status = AttendanceStatus::Hadir,
        ?string $note = null,
        ?string $date = null,
    ): Attendance {
        return Attendance::forceCreate([
            'employee_id' => $employee->id,
            'date' => $date ?? $this->day,
            'status' => $status,
            'note' => $note,
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
            'employee_id' => $this->member->id,
            'date' => $this->day,
            'status' => 'hadir',
            'check_in' => '08:00',
            'check_out' => '17:00',
            'note' => null,
        ], $override);
    }

    private function employeeIds($response): array
    {
        return collect($response->json('data'))->pluck('employee.id')->all();
    }

    public function test_hr_can_record_attendance_and_it_is_audited(): void
    {
        $this->actAs($this->hr)->postJson('/api/attendances', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'hadir')
            ->assertJsonPath('data.check_in', '08:00');

        $attendance = Attendance::firstOrFail();
        $this->assertSame($this->hr->id, $attendance->recorded_by);

        $log = AuditLog::where('action', 'attendance.created')->firstOrFail();
        $this->assertSame($this->hr->id, $log->user_id);
    }

    public function test_duplicate_record_for_same_employee_and_date_is_rejected(): void
    {
        $this->actAs($this->hr)->postJson('/api/attendances', $this->payload())->assertCreated();

        $this->actAs($this->hr)
            ->postJson('/api/attendances', $this->payload(['status' => 'sakit', 'check_in' => null, 'check_out' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');

        $this->assertSame(1, Attendance::count());
    }

    public function test_unique_constraint_exists_in_database_not_only_in_php(): void
    {
        $this->makeAttendance($this->member);

        $this->expectException(QueryException::class);

        DB::table('attendances')->insert([
            'employee_id' => $this->member->id,
            'date' => $this->day,
            'status' => 'hadir',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_non_hr_cannot_record_attendance(): void
    {
        foreach ([$this->atasanUser, $this->karyawanUser, $this->finance] as $user) {
            $this->actAs($user)->postJson('/api/attendances', $this->payload())->assertForbidden();
        }

        $this->assertSame(0, Attendance::count());
    }

    public function test_correction_keeps_trail_and_cannot_move_record(): void
    {
        $attendance = $this->makeAttendance($this->member, AttendanceStatus::Alpa);

        $this->actAs($this->hr)->putJson("/api/attendances/{$attendance->id}", [
            'status' => 'sakit',
            'note' => 'Surat dokter',
            // dua field ini harus diabaikan:
            'employee_id' => $this->outsider->id,
            'date' => '2020-01-01',
        ])->assertOk()->assertJsonPath('data.status', 'sakit');

        $fresh = $attendance->fresh();
        $this->assertSame($this->member->id, $fresh->employee_id);
        $this->assertSame($this->day, $fresh->date);

        $log = AuditLog::where('action', 'attendance.updated')
            ->where('auditable_id', $attendance->id)
            ->firstOrFail();

        $this->assertSame($this->hr->id, $log->user_id);
        $this->assertSame('alpa', $log->old_values['status']);
        $this->assertSame('sakit', $log->new_values['status']);
    }

    public function test_resubmitting_identical_values_creates_no_audit_noise(): void
    {
        $this->actAs($this->hr)->postJson('/api/attendances', $this->payload())->assertCreated();
        $id = Attendance::firstOrFail()->id;

        $this->actAs($this->hr)->putJson("/api/attendances/{$id}", [
            'status' => 'hadir',
            'check_in' => '08:00',
            'check_out' => '17:00',
        ])->assertOk();

        $this->assertSame(0, AuditLog::where('action', 'attendance.updated')->count());
    }

    public function test_non_hadir_status_cannot_have_times(): void
    {
        $this->actAs($this->hr)
            ->postJson('/api/attendances', $this->payload(['status' => 'sakit']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('check_in');
    }

    public function test_check_out_must_be_after_check_in(): void
    {
        $this->actAs($this->hr)
            ->postJson('/api/attendances', $this->payload(['check_in' => '17:00', 'check_out' => '08:00']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('check_out');
    }

    public function test_future_date_is_rejected(): void
    {
        $this->actAs($this->hr)
            ->postJson('/api/attendances', $this->payload(['date' => now()->addDay()->toDateString()]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');
    }

    public function test_inactive_employee_cannot_be_recorded(): void
    {
        $this->member->forceFill(['is_active' => false])->save();

        $this->actAs($this->hr)
            ->postJson('/api/attendances', $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('employee_id');
    }

    public function test_hr_sees_all_and_date_filter_works(): void
    {
        $this->makeAttendance($this->member);
        $this->makeAttendance($this->member2);
        $this->makeAttendance($this->outsider);
        $this->makeAttendance($this->member, date: now()->subDays(5)->toDateString());

        $this->actAs($this->hr)->getJson('/api/attendances')
            ->assertOk()
            ->assertJsonCount(4, 'data');

        $this->actAs($this->hr)->getJson("/api/attendances?date={$this->day}")
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_atasan_list_contains_only_self_and_team(): void
    {
        foreach ([$this->atasan, $this->member, $this->member2, $this->outsider] as $employee) {
            $this->makeAttendance($employee);
        }

        $res = $this->actAs($this->atasanUser)->getJson('/api/attendances')->assertOk();

        $this->assertEqualsCanonicalizing(
            [$this->atasan->id, $this->member->id, $this->member2->id],
            $this->employeeIds($res)
        );
    }

    public function test_karyawan_list_contains_only_self(): void
    {
        foreach ([$this->member, $this->member2, $this->outsider] as $employee) {
            $this->makeAttendance($employee);
        }

        $res = $this->actAs($this->karyawanUser)->getJson('/api/attendances')->assertOk();

        $this->assertSame([$this->member->id], $this->employeeIds($res));
    }

    public function test_finance_and_guest_cannot_list_attendance(): void
    {
        $this->getJson('/api/attendances')->assertUnauthorized();

        $this->actAs($this->finance)->getJson('/api/attendances')->assertForbidden();
    }

    public function test_atasan_cannot_open_attendance_outside_team(): void
    {
        $outside = $this->makeAttendance($this->outsider);
        $inside = $this->makeAttendance($this->member2);

        $this->actAs($this->atasanUser)->getJson("/api/attendances/{$outside->id}")->assertForbidden();
        $this->actAs($this->atasanUser)->getJson("/api/attendances/{$inside->id}")->assertOk();
    }

    public function test_karyawan_cannot_open_colleague_attendance(): void
    {
        $own = $this->makeAttendance($this->member);
        $colleague = $this->makeAttendance($this->member2);

        $this->actAs($this->karyawanUser)->getJson("/api/attendances/{$own->id}")->assertOk();
        $this->actAs($this->karyawanUser)->getJson("/api/attendances/{$colleague->id}")->assertForbidden();
    }

    public function test_note_is_hidden_from_atasan_but_visible_to_hr_and_owner(): void
    {
        $attendance = $this->makeAttendance($this->member, AttendanceStatus::Sakit, 'Demam berdarah');
        $url = "/api/attendances/{$attendance->id}";

        $this->actAs($this->hr)->getJson($url)->assertOk()->assertJsonPath('data.note', 'Demam berdarah');
        $this->actAs($this->karyawanUser)->getJson($url)->assertOk()->assertJsonPath('data.note', 'Demam berdarah');
        $this->actAs($this->atasanUser)->getJson($url)->assertOk()->assertJsonMissingPath('data.note');
    }
}
