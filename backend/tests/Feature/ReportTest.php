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
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobOpening;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Payroll;
use App\Models\Position;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReportTest extends TestCase
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
    private Employee $hrEmp;
    private Employee $atasanEmp;
    private Employee $member;
    private Employee $outsider;

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->hrEmp = $this->makeEmployee('H1', $this->keu, $this->hr);
        $this->makeEmployee('F1', $this->keu, $this->finance);
        $this->makeEmployee('S1', $this->keu, $this->superUser);
        $this->atasanEmp = $this->makeEmployee('E1', $this->ops, $this->atasanUser, null, 'Budi Atasan');
        $this->member = $this->makeEmployee('E2', $this->ops, $this->karyawanUser, $this->atasanEmp, 'Anggota Satu');
        $this->outsider = $this->makeEmployee('E4', $this->ops, $this->karyawan2User, null, 'Orang Luar', EmploymentStatus::Kontrak);
        $this->makeEmployee('E5', $this->ops, null, $this->atasanEmp, 'Sudah Keluar', EmploymentStatus::Tetap, false);

        // Absensi (catatan sengaja berisi hal sensitif).
        foreach ([$this->member, $this->outsider] as $employee) {
            Attendance::forceCreate([
                'employee_id' => $employee->id,
                'date' => '2030-03-04',
                'status' => $employee->is($this->member) ? AttendanceStatus::Hadir : AttendanceStatus::Sakit,
                'check_in' => $employee->is($this->member) ? '08:00' : null,
                'check_out' => $employee->is($this->member) ? '17:00' : null,
                'note' => 'Catatan rahasia sakit',
            ]);
        }

        // Cuti (alasan sengaja sensitif).
        $this->makeLeave($this->member, LeaveStatus::Approved, '2030-03-20', '2030-03-22', 3);
        $this->makeLeave($this->outsider, LeaveStatus::Pending, '2030-04-08', '2030-04-09', 2);

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
        $this->makePayroll($this->outsider, 3, true, [['type' => 'basic_salary', 'amount' => 7_654_321]]);
        $this->makePayroll($this->member, 4, false, [['type' => 'basic_salary', 'amount' => 4_444_444]]);

        // Rekrutmen (email dan telepon sengaja sensitif).
        $opening = JobOpening::forceCreate([
            'title' => 'Staff Operasional',
            'department_id' => $this->ops->id,
            'position_id' => $this->pos->id,
            'headcount' => 3,
            'status' => JobOpeningStatus::Open,
        ]);
        foreach ([
            [CandidateStage::Applied, 'rahasia1@example.com', null],
            [CandidateStage::Offer, 'rahasia2@example.com', null],
            [CandidateStage::Accepted, 'rahasia3@example.com', $this->member->id],
        ] as [$stage, $email, $converted]) {
            Candidate::forceCreate([
                'job_opening_id' => $opening->id,
                'full_name' => 'Calon '.$stage->value,
                'email' => $email,
                'phone' => '0899000111',
                'stage' => $stage,
                'converted_employee_id' => $converted,
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

    private function makeEmployee(
        string $number,
        Department $dept,
        ?User $user = null,
        ?Employee $manager = null,
        ?string $name = null,
        EmploymentStatus $status = EmploymentStatus::Tetap,
        bool $active = true,
    ): Employee {
        return Employee::forceCreate([
            'user_id' => $user?->id,
            'manager_id' => $manager?->id,
            'department_id' => $dept->id,
            'position_id' => $this->pos->id,
            'employee_number' => $number,
            'full_name' => $name ?? "Karyawan {$number}",
            'nik' => '3201010101010009',
            'phone' => '08123456789',
            'address' => 'Jl. Rahasia No. 9',
            'birth_date' => '1990-01-01',
            'hire_date' => '2024-01-15',
            'employment_status' => $status,
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
            'reason' => 'Alasan pribadi rahasia',
            'status' => $status,
        ]);
    }

    private function makePayroll(Employee $employee, int $month, bool $final, array $items, int $revision = 0, ?int $adjusts = null): Payroll
    {
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

    private function params(string $report): array
    {
        return match ($report) {
            'attendance', 'leave' => ['from' => '2030-03-01', 'to' => '2030-03-31'],
            'payroll' => ['year' => 2030, 'month' => 3],
            default => [],
        };
    }

    private function url(string $report, array $params = []): string
    {
        return "/api/reports/{$report}".($params ? '?'.http_build_query($params) : '');
    }

    private function csvLines(string $content): array
    {
        return preg_split('/\r?\n/', trim(ltrim($content, "\xEF\xBB\xBF")));
    }

    public function test_guest_unknown_report_and_role_matrix(): void
    {
        $this->getJson($this->url('employees'))->assertUnauthorized();
        $this->actAs($this->hr)->getJson($this->url('nope'))->assertNotFound();

        $everyone = [$this->hr, $this->finance, $this->superUser, $this->atasanUser, $this->karyawanUser];
        $allowed = [
            'employees' => [$this->hr, $this->superUser],
            'attendance' => [$this->hr, $this->atasanUser],
            'leave' => [$this->hr, $this->atasanUser],
            'recruitment' => [$this->hr, $this->superUser],
            'payroll' => [$this->hr, $this->finance],
        ];

        foreach ($allowed as $report => $users) {
            foreach ($everyone as $user) {
                $this->actAs($user)
                    ->getJson($this->url($report, $this->params($report)))
                    ->assertStatus(in_array($user, $users, true) ? 200 : 403);
            }
        }
    }

    public function test_employees_report_content_filters_and_data_minimization(): void
    {
        $res = $this->actAs($this->hr)->getJson($this->url('employees'))
            ->assertOk()
            ->assertJsonCount(7, 'data')
            ->assertJsonPath('meta.total', 7)
            ->assertJsonPath('meta.columns.employee_number', 'Nomor Karyawan')
            ->assertJsonPath('data.0.employee_number', 'E1')
            ->assertJsonPath('data.1.manager', 'Budi Atasan');

        foreach (['3201010101010009', '08123456789', 'Jl. Rahasia No. 9', '1990-01-01'] as $secret) {
            $this->assertStringNotContainsString($secret, $res->getContent());
        }

        $this->actAs($this->hr)->getJson($this->url('employees', ['department_id' => $this->keu->id]))
            ->assertOk()->assertJsonCount(3, 'data');

        $this->actAs($this->hr)->getJson($this->url('employees', ['employment_status' => 'kontrak']))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee_number', 'E4');

        $this->actAs($this->hr)->getJson($this->url('employees', ['active' => 0]))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee_number', 'E5');

        $this->actAs($this->hr)->getJson($this->url('employees', ['per_page' => 2]))
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 7)
            ->assertJsonPath('meta.last_page', 4);

        $this->actAs($this->hr)->getJson($this->url('employees', ['employment_status' => 'bukan']))->assertUnprocessable();
        $this->actAs($this->hr)->getJson($this->url('employees', ['per_page' => 500]))->assertUnprocessable();
    }

    public function test_attendance_report_scope_period_limit_and_no_notes(): void
    {
        $params = $this->params('attendance');

        $res = $this->actAs($this->hr)->getJson($this->url('attendance', $params))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.employee_number', 'E2')
            ->assertJsonPath('data.0.check_in', '08:00')
            ->assertJsonPath('data.0.date', '2030-03-04');
        $this->assertStringNotContainsString('Catatan rahasia sakit', $res->getContent());

        // Atasan: hanya timnya. Orang di luar tim tidak muncul.
        $res = $this->actAs($this->atasanUser)->getJson($this->url('attendance', $params))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee_number', 'E2');
        $this->assertStringNotContainsString('Orang Luar', $res->getContent());
        $this->assertStringNotContainsString('Catatan rahasia sakit', $res->getContent());

        // Mencoba menarik orang di luar tim lewat filter tetap kosong.
        $this->actAs($this->atasanUser)
            ->getJson($this->url('attendance', $params + ['employee_id' => $this->outsider->id]))
            ->assertOk()->assertJsonCount(0, 'data');

        $this->actAs($this->hr)->getJson($this->url('attendance', $params + ['status' => 'sakit']))
            ->assertOk()->assertJsonCount(1, 'data');

        // Periode wajib dan dibatasi.
        $this->actAs($this->hr)->getJson($this->url('attendance'))->assertUnprocessable();
        $this->actAs($this->hr)
            ->getJson($this->url('attendance', ['from' => '2030-03-10', 'to' => '2030-03-01']))
            ->assertUnprocessable();
        $this->actAs($this->hr)
            ->getJson($this->url('attendance', ['from' => '2030-03-01', 'to' => '2030-06-01']))
            ->assertOk();
        $this->actAs($this->hr)
            ->getJson($this->url('attendance', ['from' => '2030-03-01', 'to' => '2030-06-02']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');
    }

    public function test_leave_report_scope_overlap_and_no_reasons(): void
    {
        $wide = ['from' => '2030-03-01', 'to' => '2030-04-30'];

        $res = $this->actAs($this->hr)->getJson($this->url('leave', $wide))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.leave_type', 'Cuti Tahunan');
        $this->assertStringNotContainsString('Alasan pribadi rahasia', $res->getContent());

        $res = $this->actAs($this->atasanUser)->getJson($this->url('leave', $wide))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee_number', 'E2');
        $this->assertStringNotContainsString('Orang Luar', $res->getContent());

        // Irisan: periode satu hari di tengah cuti tetap menangkapnya; setelah cuti tidak.
        $this->actAs($this->hr)->getJson($this->url('leave', ['from' => '2030-03-21', 'to' => '2030-03-21']))
            ->assertOk()->assertJsonCount(1, 'data');
        $this->actAs($this->hr)->getJson($this->url('leave', ['from' => '2030-03-23', 'to' => '2030-03-31']))
            ->assertOk()->assertJsonCount(0, 'data');

        $this->actAs($this->hr)->getJson($this->url('leave', $wide + ['status' => 'pending']))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee_number', 'E4');
    }

    public function test_recruitment_report_hides_candidate_contact_details(): void
    {
        foreach ([$this->hr, $this->superUser] as $user) {
            $res = $this->actAs($user)->getJson($this->url('recruitment'))
                ->assertOk()
                ->assertJsonCount(3, 'data');

            foreach (['rahasia1@example.com', 'rahasia2@example.com', 'rahasia3@example.com', '0899000111'] as $secret) {
                $this->assertStringNotContainsString($secret, $res->getContent());
            }

            $this->assertSame(1, collect($res->json('data'))->where('converted', 'ya')->count());
        }

        $this->actAs($this->hr)->getJson($this->url('recruitment', ['stage' => 'offer']))
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_payroll_report_totals_adjustments_and_filters(): void
    {
        foreach ([$this->hr, $this->finance] as $user) {
            $this->actAs($user)->getJson($this->url('payroll', $this->params('payroll')))
                ->assertOk()
                ->assertJsonCount(3, 'data')
                ->assertJsonPath('data.0.employee_number', 'E2')
                ->assertJsonPath('data.0.revision_no', 0)
                ->assertJsonPath('data.0.gross', 11_000_000)
                ->assertJsonPath('data.0.deductions', 1_000_000)
                ->assertJsonPath('data.0.net', 10_000_000)
                ->assertJsonPath('data.1.revision_no', 1)
                ->assertJsonPath('data.1.gross', -500_000)
                ->assertJsonPath('data.1.net', -500_000)
                ->assertJsonPath('data.2.employee_number', 'E4')
                ->assertJsonPath('data.2.net', 7_654_321);
        }

        // Periode dan status.
        $this->actAs($this->hr)->getJson($this->url('payroll', ['year' => 2030, 'month' => 4]))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'draft');
        $this->actAs($this->hr)->getJson($this->url('payroll', ['year' => 2030, 'month' => 3, 'status' => 'draft']))
            ->assertOk()->assertJsonCount(0, 'data');
        $this->actAs($this->hr)
            ->getJson($this->url('payroll', ['year' => 2030, 'month' => 3, 'employee_id' => $this->outsider->id]))
            ->assertOk()->assertJsonCount(1, 'data');

        // Periode wajib dan valid.
        $this->actAs($this->hr)->getJson($this->url('payroll'))->assertUnprocessable();
        $this->actAs($this->hr)->getJson($this->url('payroll', ['year' => 2030, 'month' => 13]))->assertUnprocessable();

        // Pemilik slip pun tidak punya laporan payroll.
        $this->actAs($this->karyawanUser)->getJson($this->url('payroll', $this->params('payroll')))->assertForbidden();
    }

    public function test_csv_export_format_and_audit_trail(): void
    {
        // JSON tidak dicatat sebagai ekspor.
        $this->actAs($this->hr)->getJson($this->url('employees'))->assertOk();
        $this->assertSame(0, AuditLog::where('action', 'report.exported')->count());

        $res = $this->actAs($this->hr)->getJson($this->url('employees', ['format' => 'csv']))->assertOk();

        $this->assertStringContainsString('text/csv', (string) $res->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $res->headers->get('Content-Disposition'));
        $this->assertStringContainsString('laporan-employees-20300313-100000.csv', (string) $res->headers->get('Content-Disposition'));

        $content = $res->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);

        $lines = $this->csvLines($content);
        $this->assertCount(8, $lines); // header + 7 karyawan
        $this->assertStringContainsString('Nomor Karyawan', $lines[0]);
        $this->assertStringStartsWith('E1,', $lines[1]);

        foreach (['3201010101010009', '08123456789', 'Jl. Rahasia No. 9'] as $secret) {
            $this->assertStringNotContainsString($secret, $content);
        }

        $log = AuditLog::where('action', 'report.exported')->firstOrFail();
        $this->assertSame($this->hr->id, $log->user_id);
        $this->assertSame('employees', $log->new_values['report']);
        $this->assertSame(7, $log->new_values['rows']);

        // Filter ikut tercatat; format dan per_page tidak.
        $this->actAs($this->hr)
            ->getJson($this->url('payroll', ['year' => 2030, 'month' => 3, 'format' => 'csv']))
            ->assertOk()
            ->streamedContent();

        $payrollLog = AuditLog::where('action', 'report.exported')->latest('id')->firstOrFail();
        $this->assertSame('payroll', $payrollLog->new_values['report']);
        $this->assertSame(3, $payrollLog->new_values['rows']);
        $this->assertEquals(['year' => 2030, 'month' => 3], $payrollLog->new_values['filters']);
    }

    public function test_csv_keeps_negative_amounts_numeric(): void
    {
        $content = $this->actAs($this->hr)
            ->getJson($this->url('payroll', ['year' => 2030, 'month' => 3, 'format' => 'csv']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString(',finalized,-500000,0,-500000,', $content);
        $this->assertStringNotContainsString("'-500000", $content);
    }

    public function test_csv_neutralizes_spreadsheet_formulas(): void
    {
        $this->makeEmployee('X9', $this->ops, null, null, '=HYPERLINK("http://evil.test","klik")');

        $content = $this->actAs($this->hr)
            ->getJson($this->url('employees', ['format' => 'csv']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString("\"'=HYPERLINK", $content);
        $this->assertStringNotContainsString('"=HYPERLINK', $content);

        foreach (['=1+1', '+cmd', '-cmd', '@SUM(A1)', "\tx", "\rx"] as $dangerous) {
            $this->assertSame("'".$dangerous, ReportService::safeCell($dangerous));
        }

        foreach (['Budi', '', 123, -500, null, 12.5] as $safe) {
            $this->assertSame($safe, ReportService::safeCell($safe));
        }
    }

    public function test_oversized_export_is_refused_and_not_audited(): void
    {
        config(['hrm.report_max_export_rows' => 2]);

        $this->actAs($this->hr)
            ->getJson($this->url('employees', ['format' => 'csv']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('format');

        $this->assertSame(0, AuditLog::where('action', 'report.exported')->count());

        // Tampilan JSON berpaginasi tidak kena batas ekspor.
        $this->actAs($this->hr)->getJson($this->url('employees'))->assertOk();
    }

    public function test_reports_are_never_publicly_cacheable(): void
    {
        $users = [
            'employees' => $this->hr,
            'attendance' => $this->atasanUser,
            'leave' => $this->atasanUser,
            'recruitment' => $this->superUser,
            'payroll' => $this->finance,
        ];

        foreach ($users as $report => $user) {
            foreach ([[], ['format' => 'csv']] as $extra) {
                $res = $this->actAs($user)->getJson($this->url($report, $this->params($report) + $extra))->assertOk();

                $cacheControl = (string) $res->headers->get('Cache-Control');

                $this->assertStringContainsString('no-store', $cacheControl);
                $this->assertStringContainsString('private', $cacheControl);
                $this->assertStringNotContainsString('public', $cacheControl);

                if ($extra) {
                    $res->streamedContent(); // jalankan stream supaya tidak menggantung
                }
            }
        }
    }
}
