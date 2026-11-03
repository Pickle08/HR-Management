<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\CandidateStage;
use App\Enums\EmploymentStatus;
use App\Enums\JobOpeningStatus;
use App\Enums\LeaveStatus;
use App\Enums\PayrollStatus;
use App\Enums\Role;
use App\Models\Attendance;
use App\Models\Candidate;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobOpening;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Payroll;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private Department $ops;
    private Department $keu;
    private Position $pos;
    private LeaveType $annual;
    private User $hr;
    private User $finance;
    private User $superUser;
    private User $atasanUser;
    private User $karyawanUser;
    private User $karyawan2User;
    private User $lockedUser;
    private Employee $hrEmp;
    private Employee $finEmp;
    private Employee $superEmp;
    private Employee $atasanEmp;
    private Employee $member;
    private Employee $member2;
    private Employee $outsider;
    private Employee $inactive;

    protected function setUp(): void
    {
        parent::setUp();

        // Rabu 13 Mar 2030.
        $this->travelTo(Carbon::parse('2030-03-13 10:00:00'));

        $this->ops = Department::create(['name' => 'Operasional']);
        $this->keu = Department::create(['name' => 'Keuangan']);
        $this->pos = Position::create(['name' => 'Staff']);
        $this->annual = LeaveType::create(['name' => 'Cuti Tahunan', 'annual_quota' => 12, 'is_active' => true]);

        $this->hr = $this->makeUser(Role::Hr, 'hr@t.test');
        $this->finance = $this->makeUser(Role::Finance, 'fin@t.test');
        $this->superUser = $this->makeUser(Role::SuperAdmin, 'super@t.test');
        $this->atasanUser = $this->makeUser(Role::Atasan, 'atasan@t.test');
        $this->karyawanUser = $this->makeUser(Role::Karyawan, 'kary@t.test');
        $this->karyawan2User = $this->makeUser(Role::Karyawan, 'kary2@t.test');
        $this->lockedUser = $this->makeUser(Role::Karyawan, 'locked@t.test', false);

        $this->hrEmp = $this->makeEmployee('H1', $this->keu, $this->hr);
        $this->finEmp = $this->makeEmployee('F1', $this->keu, $this->finance);
        $this->superEmp = $this->makeEmployee('S1', $this->keu, $this->superUser);
        $this->atasanEmp = $this->makeEmployee('E1', $this->ops, $this->atasanUser, null, 'Budi Atasan');
        $this->member = $this->makeEmployee('E2', $this->ops, $this->karyawanUser, $this->atasanEmp, 'Anggota Satu');
        $this->member2 = $this->makeEmployee('E3', $this->ops, null, $this->atasanEmp, 'Anggota Dua');
        $this->outsider = $this->makeEmployee('E4', $this->ops, $this->karyawan2User, null, 'Orang Luar');
        $this->inactive = $this->makeEmployee('E5', $this->ops, null, $this->atasanEmp, 'Sudah Keluar', false);

        LeaveBalance::forceCreate([
            'employee_id' => $this->member->id,
            'leave_type_id' => $this->annual->id,
            'year' => 2030,
            'entitled_days' => 12,
        ]);

        // Cuti.
        $this->makeLeave($this->member, LeaveStatus::Approved, '2030-03-20', '2030-03-22', 3);
        $this->makeLeave($this->member, LeaveStatus::Pending, '2030-04-01', '2030-04-02', 2);
        $this->makeLeave($this->member2, LeaveStatus::Approved, '2030-03-12', '2030-03-14', 3); // meliputi hari ini
        $this->makeLeave($this->outsider, LeaveStatus::Pending, '2030-04-08', '2030-04-09', 2);
        $this->makeLeave($this->atasanEmp, LeaveStatus::Pending, '2030-04-15', '2030-04-16', 2);

        // Absensi.
        $this->makeAttendance($this->member, AttendanceStatus::Hadir, '2030-03-04');
        $this->makeAttendance($this->member, AttendanceStatus::Izin, '2030-03-05');
        $this->makeAttendance($this->member, AttendanceStatus::Hadir, '2030-03-13');
        $this->makeAttendance($this->outsider, AttendanceStatus::Sakit, '2030-03-13');

        // Payroll Maret 2030.
        $memberMarch = $this->makePayroll($this->member, 3, true, [
            ['type' => 'basic_salary', 'amount' => 10_000_000],
            ['type' => 'allowance', 'label' => 'Transport', 'amount' => 1_000_000],
            ['type' => 'bpjs', 'amount' => 400_000],
            ['type' => 'pph21', 'amount' => 600_000],
        ]);
        $this->makePayroll($this->member, 3, true, [
            ['type' => 'bonus', 'label' => 'Koreksi bonus', 'amount' => -500_000],
        ], revision: 1, adjusts: $memberMarch->id);
        $this->makePayroll($this->member2, 3, false, [['type' => 'basic_salary', 'amount' => 5_000_000]]);
        $this->makePayroll($this->outsider, 3, true, [['type' => 'basic_salary', 'amount' => 7_654_321]]);
        $this->makePayroll($this->atasanEmp, 3, true, [['type' => 'basic_salary', 'amount' => 8_888_888]]);
        // Draft periode berikutnya: tidak boleh jadi "slip terakhir".
        $this->makePayroll($this->member, 4, false, [['type' => 'basic_salary', 'amount' => 4_444_444]]);

        // Rekrutmen.
        $opening = JobOpening::forceCreate([
            'title' => 'Staff Operasional',
            'department_id' => $this->ops->id,
            'position_id' => $this->pos->id,
            'headcount' => 3,
            'status' => JobOpeningStatus::Open,
        ]);
        foreach ([
            ['a@x.com', CandidateStage::Applied],
            ['b@x.com', CandidateStage::Applied],
            ['c@x.com', CandidateStage::Offer],
            ['d@x.com', CandidateStage::Accepted],
            ['e@x.com', CandidateStage::Rejected],
        ] as [$email, $stage]) {
            Candidate::forceCreate([
                'job_opening_id' => $opening->id,
                'full_name' => 'Calon '.$email,
                'email' => $email,
                'stage' => $stage,
            ]);
        }
    }

    private function makeUser(Role $role, string $email, bool $active = true): User
    {
        return User::forceCreate([
            'name' => $role->value,
            'email' => $email,
            'password' => 'password',
            'role' => $role,
            'is_active' => $active,
        ]);
    }

    private function makeEmployee(
        string $number,
        Department $dept,
        ?User $user = null,
        ?Employee $manager = null,
        ?string $name = null,
        bool $active = true,
    ): Employee {
        return Employee::forceCreate([
            'user_id' => $user?->id,
            'manager_id' => $manager?->id,
            'department_id' => $dept->id,
            'position_id' => $this->pos->id,
            'employee_number' => $number,
            'full_name' => $name ?? "Karyawan {$number}",
            'hire_date' => '2024-01-15',
            'employment_status' => EmploymentStatus::Tetap,
            'is_active' => $active,
        ]);
    }

    private function makeLeave(Employee $employee, LeaveStatus $status, string $start, string $end, int $days): LeaveRequest
    {
        return LeaveRequest::forceCreate([
            'employee_id' => $employee->id,
            'leave_type_id' => $this->annual->id,
            'year' => (int) substr($start, 0, 4),
            'start_date' => $start,
            'end_date' => $end,
            'days' => $days,
            'status' => $status,
        ]);
    }

    private function makeAttendance(Employee $employee, AttendanceStatus $status, string $date): Attendance
    {
        return Attendance::forceCreate([
            'employee_id' => $employee->id,
            'date' => $date,
            'status' => $status,
        ]);
    }

    private function makePayroll(
        Employee $employee,
        int $month,
        bool $final,
        array $items,
        int $revision = 0,
        ?int $adjusts = null,
    ): Payroll {
        $payroll = new Payroll();
        $payroll->forceFill([
            'employee_id' => $employee->id,
            'year' => 2030,
            'month' => $month,
            'revision_no' => $revision,
            'adjusts_payroll_id' => $adjusts,
            'status' => PayrollStatus::Draft,
        ])->save();

        foreach ($items as $item) {
            $payroll->items()->create($item);
        }

        if ($final) {
            // Query builder: sengaja melewati guard model, khusus menyiapkan data uji.
            Payroll::whereKey($payroll->id)->update([
                'status' => 'finalized',
                'finalized_by' => $this->finance->id,
                'finalized_at' => now(),
            ]);
        }

        return $payroll->fresh();
    }

    /** Guard Sanctum menyimpan user dari request sebelumnya, jadi di-reset tiap ganti user. */
    private function actAs(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user);
    }

    public function test_guest_is_rejected_and_each_role_gets_only_its_own_blocks(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();

        $cases = [
            [$this->karyawanUser, 'karyawan', null],
            [$this->atasanUser, 'atasan', 'team'],
            [$this->hr, 'hr', 'hr'],
            [$this->finance, 'finance', 'finance'],
            [$this->superUser, 'super_admin', 'admin'],
        ];

        foreach ($cases as [$user, $role, $block]) {
            $res = $this->actAs($user)->getJson('/api/dashboard')
                ->assertOk()
                ->assertJsonPath('data.role', $role)
                ->assertJsonPath('data.date', '2030-03-13');

            foreach (['team', 'hr', 'finance', 'admin'] as $candidate) {
                $candidate === $block
                    ? $res->assertJsonStructure(['data' => [$candidate]])
                    : $res->assertJsonMissingPath("data.{$candidate}");
            }
        }
    }

    public function test_karyawan_dashboard_shows_only_own_data(): void
    {
        $res = $this->actAs($this->karyawanUser)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.me.employee.employee_number', 'E2')
            ->assertJsonPath('data.me.employee.department', 'Operasional')
            ->assertJsonPath('data.me.leave.balances.0.leave_type', 'Cuti Tahunan')
            ->assertJsonPath('data.me.leave.balances.0.entitled_days', 12)
            ->assertJsonPath('data.me.leave.balances.0.used_days', 3)
            ->assertJsonPath('data.me.leave.balances.0.pending_days', 2)
            ->assertJsonPath('data.me.leave.balances.0.remaining_days', 7)
            ->assertJsonPath('data.me.leave.pending_requests', 1)
            ->assertJsonPath('data.me.leave.next_leave.start_date', '2030-03-20')
            ->assertJsonPath('data.me.attendance_this_month.hadir', 2)
            ->assertJsonPath('data.me.attendance_this_month.izin', 1)
            ->assertJsonPath('data.me.attendance_this_month.sakit', 0)
            ->assertJsonPath('data.me.attendance_this_month.alpa', 0);

        // Tidak ada jejak orang lain atau gaji yang bukan miliknya.
        $content = $res->getContent();
        foreach (['Orang Luar', 'Anggota Dua', 'Budi Atasan', '7654321', '8888888', '4444444'] as $secret) {
            $this->assertStringNotContainsString($secret, $content);
        }

        $this->assertStringContainsString('private', (string) $res->headers->get('Cache-Control'));
    }

    public function test_latest_payslip_is_final_original_plus_adjustments_never_a_draft(): void
    {
        $this->actAs($this->karyawanUser)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.me.latest_payslip.year', 2030)
            ->assertJsonPath('data.me.latest_payslip.month', 3)
            ->assertJsonPath('data.me.latest_payslip.gross', 11_000_000)
            ->assertJsonPath('data.me.latest_payslip.deductions', 1_000_000)
            ->assertJsonPath('data.me.latest_payslip.net', 10_000_000)
            ->assertJsonPath('data.me.latest_payslip.adjustments_net', -500_000);

        // member2 hanya punya draft: tidak ada slip.
        $member2User = $this->makeUser(Role::Karyawan, 'm2@t.test');
        $this->member2->forceFill(['user_id' => $member2User->id])->save();

        $this->actAs($member2User)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.me.latest_payslip', null);
    }

    public function test_atasan_dashboard_is_limited_to_own_team(): void
    {
        $res = $this->actAs($this->atasanUser)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.team.size', 2)
            ->assertJsonPath('data.team.pending_leave.count', 1)
            ->assertJsonPath('data.team.pending_leave.oldest.0.employee.id', $this->member->id)
            ->assertJsonCount(1, 'data.team.on_leave_today')
            ->assertJsonPath('data.team.on_leave_today.0.employee.id', $this->member2->id)
            ->assertJsonPath('data.team.attendance_today.recorded', 1)
            ->assertJsonPath('data.team.attendance_today.by_status.hadir', 1)
            ->assertJsonPath('data.team.attendance_today.not_recorded', 1)
            ->assertJsonPath('data.me.employee.employee_number', 'E1');

        // Orang di luar tim dan karyawan nonaktif tidak muncul.
        $content = $res->getContent();
        $this->assertStringNotContainsString('Orang Luar', $content);
        $this->assertStringNotContainsString('Sudah Keluar', $content);
    }

    public function test_atasan_dashboard_never_leaks_team_payroll(): void
    {
        $res = $this->actAs($this->atasanUser)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.me.latest_payslip.net', 8_888_888)
            ->assertJsonMissingPath('data.team.payroll');

        // Gaji bawahan (10.000.000 neto, 11.000.000 bruto, draft 5.000.000) dan orang luar.
        $content = $res->getContent();
        foreach (['10000000', '11000000', '5000000', '7654321', '4444444'] as $secret) {
            $this->assertStringNotContainsString($secret, $content);
        }
    }

    public function test_hr_dashboard_summaries(): void
    {
        $this->actAs($this->hr)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.hr.employees.active', 7)
            ->assertJsonPath('data.hr.employees.inactive', 1)
            ->assertJsonPath('data.hr.employees.by_employment_status.tetap', 7)
            ->assertJsonPath('data.hr.employees.by_employment_status.kontrak', 0)
            ->assertJsonPath('data.hr.employees.by_department.0.name', 'Keuangan')
            ->assertJsonPath('data.hr.employees.by_department.0.active_employees', 3)
            ->assertJsonPath('data.hr.employees.by_department.1.name', 'Operasional')
            ->assertJsonPath('data.hr.employees.by_department.1.active_employees', 4)
            ->assertJsonPath('data.hr.leave.pending_count', 3)
            ->assertJsonPath('data.hr.leave.on_leave_today', 1)
            ->assertJsonPath('data.hr.attendance_today.recorded', 2)
            ->assertJsonPath('data.hr.attendance_today.by_status.hadir', 1)
            ->assertJsonPath('data.hr.attendance_today.by_status.sakit', 1)
            ->assertJsonPath('data.hr.attendance_today.not_recorded', 5)
            ->assertJsonPath('data.hr.recruitment.open_openings', 1)
            ->assertJsonPath('data.hr.recruitment.pipeline.applied', 2)
            ->assertJsonPath('data.hr.recruitment.pipeline.offer', 1)
            ->assertJsonPath('data.hr.recruitment.pipeline.screening', 0)
            ->assertJsonPath('data.hr.recruitment.accepted_awaiting_conversion', 1)
            ->assertJsonPath('data.hr.payroll.period.month', 3)
            ->assertJsonPath('data.hr.payroll.draft', 1)
            ->assertJsonPath('data.hr.payroll.finalized', 4)
            ->assertJsonPath('data.hr.payroll.missing', 3)
            ->assertJsonMissingPath('data.hr.payroll.finalized_totals');
    }

    public function test_finance_totals_include_adjustments_and_exclude_drafts(): void
    {
        // bruto 11.000.000 - 500.000 + 7.654.321 + 8.888.888, potongan 1.000.000. Draft 5.000.000 tidak ikut.
        $this->actAs($this->finance)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.finance.period.year', 2030)
            ->assertJsonPath('data.finance.period.month', 3)
            ->assertJsonPath('data.finance.draft', 1)
            ->assertJsonPath('data.finance.finalized', 4)
            ->assertJsonPath('data.finance.missing', 3)
            ->assertJsonPath('data.finance.finalized_totals.gross', 27_043_209)
            ->assertJsonPath('data.finance.finalized_totals.deductions', 1_000_000)
            ->assertJsonPath('data.finance.finalized_totals.net', 26_043_209);
    }

    public function test_finance_totals_match_the_payroll_model_totals(): void
    {
        $expected = Payroll::query()
            ->where('year', 2030)
            ->where('month', 3)
            ->where('status', 'finalized')
            ->withTotals()
            ->get()
            ->map(fn (Payroll $p) => $p->totals());

        $res = $this->actAs($this->finance)->getJson('/api/dashboard')->assertOk();

        $this->assertSame($expected->sum('gross'), $res->json('data.finance.finalized_totals.gross'));
        $this->assertSame($expected->sum('deductions'), $res->json('data.finance.finalized_totals.deductions'));
        $this->assertSame($expected->sum('net'), $res->json('data.finance.finalized_totals.net'));
    }

    public function test_finance_period_filter_and_validation(): void
    {
        $this->actAs($this->finance)->getJson('/api/dashboard?year=2030&month=2')
            ->assertOk()
            ->assertJsonPath('data.finance.period.month', 2)
            ->assertJsonPath('data.finance.draft', 0)
            ->assertJsonPath('data.finance.finalized', 0)
            ->assertJsonPath('data.finance.missing', 7)
            ->assertJsonPath('data.finance.finalized_totals.net', 0);

        $this->actAs($this->finance)->getJson('/api/dashboard?month=13')->assertUnprocessable();
        $this->actAs($this->finance)->getJson('/api/dashboard?year=1999')->assertUnprocessable();
    }

    public function test_super_admin_sees_accounts_but_no_payroll_or_employee_data(): void
    {
        $res = $this->actAs($this->superUser)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.admin.accounts.total', 7)
            ->assertJsonPath('data.admin.accounts.active', 6)
            ->assertJsonPath('data.admin.accounts.locked', 1)
            ->assertJsonPath('data.admin.accounts.by_role.hr', 1)
            ->assertJsonPath('data.admin.accounts.by_role.finance', 1)
            ->assertJsonPath('data.admin.accounts.by_role.super_admin', 1)
            ->assertJsonPath('data.admin.accounts.by_role.atasan', 1)
            ->assertJsonPath('data.admin.accounts.by_role.karyawan', 3)
            ->assertJsonPath('data.admin.employees_without_account', 1)
            // Pengajuan karyawan tanpa atasan (Orang Luar dan Budi Atasan), bukan milik tim Budi.
            ->assertJsonPath('data.admin.leave_awaiting_decision', 2)
            ->assertJsonPath('data.me.latest_payslip', null);

        $content = $res->getContent();
        foreach (['7654321', '8888888', '10000000'] as $secret) {
            $this->assertStringNotContainsString($secret, $content);
        }
    }

    public function test_user_without_employee_profile_gets_empty_me(): void
    {
        $loner = $this->makeUser(Role::Karyawan, 'loner@t.test');

        $this->actAs($loner)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.role', 'karyawan')
            ->assertJsonPath('data.me', null);
    }

    public function test_dashboard_is_never_publicly_cacheable(): void
    {
        foreach ([$this->karyawanUser, $this->atasanUser, $this->hr, $this->finance, $this->superUser] as $user) {
            $res = $this->actAs($user)->getJson('/api/dashboard')->assertOk();

            $cacheControl = (string) $res->headers->get('Cache-Control');

            $this->assertStringContainsString('private', $cacheControl);
            $this->assertStringNotContainsString('public', $cacheControl);
        }
    }
}
