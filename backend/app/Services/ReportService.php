<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\CandidateStage;
use App\Enums\EmploymentStatus;
use App\Enums\LeaveStatus;
use App\Enums\PayrollItemType;
use App\Enums\PayrollStatus;
use App\Enums\Role;
use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Setiap laporan = query dengan daftar kolom eksplisit. Kolom sensitif (nik, telepon,
 * alamat, catatan, alasan, email kandidat) tidak pernah di-select, jadi tidak bisa bocor.
 */
class ReportService
{
    public const REPORTS = ['employees', 'attendance', 'leave', 'recruitment', 'payroll'];

    public const MAX_PERIOD_DAYS = 92;

    /** @return list<Role> */
    public function allowedRoles(string $report): array
    {
        return match ($report) {
            'employees', 'recruitment' => [Role::Hr, Role::SuperAdmin],
            'attendance', 'leave' => [Role::Hr, Role::Atasan],
            'payroll' => [Role::Hr, Role::Finance],
            default => [],
        };
    }

    public function canView(User $user, string $report): bool
    {
        return in_array($user->role, $this->allowedRoles($report), true);
    }

    public function rules(string $report): array
    {
        $period = [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];

        return [
            'format' => ['nullable', 'in:json,csv'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ] + match ($report) {
            'employees' => [
                'department_id' => ['nullable', 'integer'],
                'employment_status' => ['nullable', Rule::enum(EmploymentStatus::class)],
                'active' => ['nullable', 'boolean'],
            ],
            'attendance' => $period + [
                'status' => ['nullable', Rule::enum(AttendanceStatus::class)],
                'employee_id' => ['nullable', 'integer'],
                'department_id' => ['nullable', 'integer'],
            ],
            'leave' => $period + [
                'status' => ['nullable', Rule::enum(LeaveStatus::class)],
                'employee_id' => ['nullable', 'integer'],
                'leave_type_id' => ['nullable', 'integer'],
            ],
            'recruitment' => [
                'job_opening_id' => ['nullable', 'integer'],
                'stage' => ['nullable', Rule::enum(CandidateStage::class)],
            ],
            'payroll' => [
                'year' => ['required', 'integer', 'between:2000,2100'],
                'month' => ['required', 'integer', 'between:1,12'],
                'status' => ['nullable', Rule::enum(PayrollStatus::class)],
                'employee_id' => ['nullable', 'integer'],
            ],
        };
    }

    public function assertPeriodWithinLimit(array $filters): void
    {
        if (!isset($filters['from'], $filters['to'])) {
            return;
        }

        $days = CarbonImmutable::parse($filters['from'])->diffInDays(CarbonImmutable::parse($filters['to']));

        if ($days > self::MAX_PERIOD_DAYS) {
            throw ValidationException::withMessages([
                'to' => ['Rentang laporan maksimal ' . self::MAX_PERIOD_DAYS . ' hari.'],
            ]);
        }
    }

    /** @return array{query: Builder, columns: array<string, string>, row: \Closure} */
    public function definition(string $report, User $user, array $f): array
    {
        return match ($report) {
            'employees' => $this->employees($f),
            'attendance' => $this->attendance($user, $f),
            'leave' => $this->leave($user, $f),
            'recruitment' => $this->recruitment($f),
            'payroll' => $this->payroll($f),
        };
    }

    /**
     * Cegah CSV injection: sel teks yang diawali karakter rumus diberi awalan petik.
     * Angka (termasuk negatif) tidak disentuh.
     */
    public static function safeCell(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }

    private function employees(array $f): array
    {
        $query = DB::table('employees')
            ->join('departments', 'departments.id', '=', 'employees.department_id')
            ->join('positions', 'positions.id', '=', 'employees.position_id')
            ->leftJoin('employees as managers', 'managers.id', '=', 'employees.manager_id')
            ->select([
                'employees.employee_number',
                'employees.full_name',
                'departments.name as department',
                'positions.name as position',
                'employees.employment_status',
                'employees.hire_date',
                'employees.end_date',
                'employees.is_active',
                'managers.full_name as manager',
            ])
            ->when($f['department_id'] ?? null, fn($q, $v) => $q->where('employees.department_id', $v))
            ->when($f['employment_status'] ?? null, fn($q, $v) => $q->where('employees.employment_status', $v))
            ->when(isset($f['active']), fn($q) => $q->where(
                'employees.is_active',
                filter_var($f['active'], FILTER_VALIDATE_BOOLEAN)
            ))
            ->orderBy('employees.employee_number');

        return [
            'query' => $query,
            'columns' => [
                'employee_number' => 'Nomor Karyawan',
                'full_name' => 'Nama',
                'department' => 'Departemen',
                'position' => 'Jabatan',
                'employment_status' => 'Status Kerja',
                'hire_date' => 'Tanggal Masuk',
                'end_date' => 'Tanggal Berakhir',
                'active' => 'Aktif',
                'manager' => 'Atasan',
            ],
            'row' => fn(object $r) => [
                'employee_number' => $r->employee_number,
                'full_name' => $r->full_name,
                'department' => $r->department,
                'position' => $r->position,
                'employment_status' => $r->employment_status,
                'hire_date' => $this->day($r->hire_date),
                'end_date' => $this->day($r->end_date),
                'active' => $r->is_active ? 'ya' : 'tidak',
                'manager' => $r->manager,
            ],
        ];
    }

    private function attendance(User $user, array $f): array
    {
        $query = DB::table('attendances')
            ->join('employees', 'employees.id', '=', 'attendances.employee_id')
            ->join('departments', 'departments.id', '=', 'employees.department_id')
            ->select([
                'attendances.date',
                'employees.employee_number',
                'employees.full_name',
                'departments.name as department',
                'attendances.status',
                'attendances.check_in',
                'attendances.check_out',
            ])
            // Cakupan data: aturan yang sama dengan endpoint lain.
            ->whereIn('attendances.employee_id', Employee::query()->visibleTo($user)->select('id'))
            ->whereBetween('attendances.date', [$f['from'], $f['to']])
            ->when($f['status'] ?? null, fn($q, $v) => $q->where('attendances.status', $v))
            ->when($f['employee_id'] ?? null, fn($q, $v) => $q->where('attendances.employee_id', $v))
            ->when($f['department_id'] ?? null, fn($q, $v) => $q->where('employees.department_id', $v))
            ->orderBy('attendances.date')
            ->orderBy('employees.employee_number');

        return [
            'query' => $query,
            'columns' => [
                'date' => 'Tanggal',
                'employee_number' => 'Nomor Karyawan',
                'full_name' => 'Nama',
                'department' => 'Departemen',
                'status' => 'Status',
                'check_in' => 'Jam Masuk',
                'check_out' => 'Jam Pulang',
            ],
            'row' => fn(object $r) => [
                'date' => $this->day($r->date),
                'employee_number' => $r->employee_number,
                'full_name' => $r->full_name,
                'department' => $r->department,
                'status' => $r->status,
                'check_in' => $r->check_in ? substr($r->check_in, 0, 5) : null,
                'check_out' => $r->check_out ? substr($r->check_out, 0, 5) : null,
            ],
        ];
    }

    private function leave(User $user, array $f): array
    {
        $query = DB::table('leave_requests')
            ->join('employees', 'employees.id', '=', 'leave_requests.employee_id')
            ->join('departments', 'departments.id', '=', 'employees.department_id')
            ->join('leave_types', 'leave_types.id', '=', 'leave_requests.leave_type_id')
            ->select([
                'employees.employee_number',
                'employees.full_name',
                'departments.name as department',
                'leave_types.name as leave_type',
                'leave_requests.start_date',
                'leave_requests.end_date',
                'leave_requests.days',
                'leave_requests.status',
            ])
            ->whereIn('leave_requests.employee_id', Employee::query()->visibleTo($user)->select('id'))
            // Semua pengajuan yang rentangnya beririsan dengan periode laporan.
            ->where('leave_requests.start_date', '<=', $f['to'])
            ->where('leave_requests.end_date', '>=', $f['from'])
            ->when($f['status'] ?? null, fn($q, $v) => $q->where('leave_requests.status', $v))
            ->when($f['employee_id'] ?? null, fn($q, $v) => $q->where('leave_requests.employee_id', $v))
            ->when($f['leave_type_id'] ?? null, fn($q, $v) => $q->where('leave_requests.leave_type_id', $v))
            ->orderBy('leave_requests.start_date')
            ->orderBy('employees.employee_number');

        return [
            'query' => $query,
            'columns' => [
                'employee_number' => 'Nomor Karyawan',
                'full_name' => 'Nama',
                'department' => 'Departemen',
                'leave_type' => 'Jenis Cuti',
                'start_date' => 'Mulai',
                'end_date' => 'Selesai',
                'days' => 'Hari Kerja',
                'status' => 'Status',
            ],
            'row' => fn(object $r) => [
                'employee_number' => $r->employee_number,
                'full_name' => $r->full_name,
                'department' => $r->department,
                'leave_type' => $r->leave_type,
                'start_date' => $this->day($r->start_date),
                'end_date' => $this->day($r->end_date),
                'days' => (int) $r->days,
                'status' => $r->status,
            ],
        ];
    }

    private function recruitment(array $f): array
    {
        $query = DB::table('candidates')
            ->join('job_openings', 'job_openings.id', '=', 'candidates.job_opening_id')
            ->select([
                'job_openings.title as opening',
                'candidates.full_name',
                'candidates.stage',
                'candidates.converted_employee_id',
                'candidates.created_at',
            ])
            ->when($f['job_opening_id'] ?? null, fn($q, $v) => $q->where('candidates.job_opening_id', $v))
            ->when($f['stage'] ?? null, fn($q, $v) => $q->where('candidates.stage', $v))
            ->orderBy('job_openings.title')
            ->orderBy('candidates.id');

        return [
            'query' => $query,
            'columns' => [
                'opening' => 'Lowongan',
                'full_name' => 'Nama Kandidat',
                'stage' => 'Tahap',
                'converted' => 'Sudah Jadi Karyawan',
                'applied_at' => 'Tanggal Melamar',
            ],
            'row' => fn(object $r) => [
                'opening' => $r->opening,
                'full_name' => $r->full_name,
                'stage' => $r->stage,
                'converted' => $r->converted_employee_id ? 'ya' : 'tidak',
                'applied_at' => $this->day($r->created_at),
            ],
        ];
    }

    private function payroll(array $f): array
    {
        // Definisi pendapatan vs potongan tetap satu sumber: enum PayrollItemType.
        $gross = DB::table('payroll_items')
            ->selectRaw('COALESCE(SUM(amount), 0)')
            ->whereColumn('payroll_items.payroll_id', 'payrolls.id')
            ->whereIn('payroll_items.type', PayrollItemType::earnings());

        $deductions = DB::table('payroll_items')
            ->selectRaw('COALESCE(SUM(amount), 0)')
            ->whereColumn('payroll_items.payroll_id', 'payrolls.id')
            ->whereIn('payroll_items.type', PayrollItemType::deductions());

        $query = DB::table('payrolls')
            ->join('employees', 'employees.id', '=', 'payrolls.employee_id')
            ->join('departments', 'departments.id', '=', 'employees.department_id')
            ->select([
                'employees.employee_number',
                'employees.full_name',
                'departments.name as department',
                'payrolls.year',
                'payrolls.month',
                'payrolls.revision_no',
                'payrolls.status',
                'payrolls.finalized_at',
            ])
            ->selectSub($gross, 'gross')
            ->selectSub($deductions, 'deductions')
            ->where('payrolls.year', $f['year'])
            ->where('payrolls.month', $f['month'])
            ->when($f['status'] ?? null, fn($q, $v) => $q->where('payrolls.status', $v))
            ->when($f['employee_id'] ?? null, fn($q, $v) => $q->where('payrolls.employee_id', $v))
            ->orderBy('employees.employee_number')
            ->orderBy('payrolls.revision_no');

        return [
            'query' => $query,
            'columns' => [
                'employee_number' => 'Nomor Karyawan',
                'full_name' => 'Nama',
                'department' => 'Departemen',
                'year' => 'Tahun',
                'month' => 'Bulan',
                'revision_no' => 'Revisi',
                'status' => 'Status',
                'gross' => 'Bruto',
                'deductions' => 'Potongan',
                'net' => 'Neto',
                'finalized_at' => 'Difinalisasi',
            ],
            'row' => fn(object $r) => [
                'employee_number' => $r->employee_number,
                'full_name' => $r->full_name,
                'department' => $r->department,
                'year' => (int) $r->year,
                'month' => (int) $r->month,
                'revision_no' => (int) $r->revision_no,
                'status' => $r->status,
                'gross' => (int) $r->gross,
                'deductions' => (int) $r->deductions,
                'net' => (int) $r->gross - (int) $r->deductions,
                'finalized_at' => $r->finalized_at,
            ],
        ];
    }

    /** Kolom date bisa berisi jam tergantung database; laporan selalu Y-m-d. */
    private function day(?string $value): ?string
    {
        return $value ? substr($value, 0, 10) : null;
    }
}
