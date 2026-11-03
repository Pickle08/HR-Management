<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\CandidateStage;
use App\Enums\EmploymentStatus;
use App\Enums\JobOpeningStatus;
use App\Enums\LeaveStatus;
use App\Enums\PayrollItemType;
use App\Enums\PayrollStatus;
use App\Enums\Role;
use App\Models\Attendance;
use App\Models\Candidate;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobOpening;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\Payroll;
use App\Models\PayrollItem;
use App\Models\User;

/**
 * Bentuk respons ditentukan role pengguna, bukan parameter dari klien.
 * Setiap blok hanya diisi dari data yang memang boleh dilihat role tersebut.
 */
class DashboardService
{
    public function forUser(User $user, int $year, int $month): array
    {
        $own = $user->employee;

        $data = [
            'role' => $user->role->value,
            'date' => now()->toDateString(),
            'me' => $own ? $this->employeeSummary($own) : null,
        ];

        return match ($user->role) {
            Role::Karyawan => $data,
            Role::Atasan => $data + ['team' => $own ? $this->team($own) : null],
            Role::Hr => $data + ['hr' => $this->hr($year, $month)],
            Role::Finance => $data + ['finance' => $this->finance($year, $month)],
            Role::SuperAdmin => $data + ['admin' => $this->admin($own)],
        };
    }

    /** Ringkasan satu karyawan tentang dirinya sendiri. */
    private function employeeSummary(Employee $employee): array
    {
        $today = now();
        $employee->loadMissing(['department:id,name', 'position:id,name']);

        $balances = LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('year', $today->year)
            ->withUsage()
            ->with('leaveType:id,name')
            ->orderBy('leave_type_id')
            ->get()
            ->map(fn (LeaveBalance $b) => [
                'leave_type' => $b->leaveType->name,
                'entitled_days' => $b->entitled_days,
                'used_days' => (int) $b->approved_days,
                'pending_days' => (int) $b->pending_days,
                'remaining_days' => $b->entitled_days - (int) $b->approved_days - (int) $b->pending_days,
            ])
            ->values();

        $nextLeave = LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->where('status', LeaveStatus::Approved->value)
            ->where('end_date', '>=', $today->toDateString())
            ->orderBy('start_date')
            ->first(['id', 'start_date', 'end_date', 'days']);

        $attendance = Attendance::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('date', [
                $today->copy()->startOfMonth()->toDateString(),
                $today->copy()->endOfMonth()->toDateString(),
            ])
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'employee' => [
                'id' => $employee->id,
                'employee_number' => $employee->employee_number,
                'full_name' => $employee->full_name,
                'department' => $employee->department?->name,
                'position' => $employee->position?->name,
            ],
            'leave' => [
                'balances' => $balances,
                'pending_requests' => LeaveRequest::query()
                    ->where('employee_id', $employee->id)
                    ->where('status', LeaveStatus::Pending->value)
                    ->count(),
                'next_leave' => $nextLeave ? [
                    'start_date' => $nextLeave->start_date,
                    'end_date' => $nextLeave->end_date,
                    'days' => $nextLeave->days,
                ] : null,
            ],
            'attendance_this_month' => $this->attendanceCounts($attendance),
            'latest_payslip' => $this->latestPayslip($employee),
        ];
    }

    /**
     * Slip milik sendiri: hanya payroll asli yang sudah final, ditambah selisih
     * koreksi final di periode yang sama. Draft tidak pernah ikut.
     */
    private function latestPayslip(Employee $employee): ?array
    {
        $payslip = Payroll::query()
            ->where('employee_id', $employee->id)
            ->where('status', PayrollStatus::Finalized->value)
            ->where('revision_no', 0)
            ->withTotals()
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->first();

        if (! $payslip) {
            return null;
        }

        $adjustmentsNet = Payroll::query()
            ->where('employee_id', $employee->id)
            ->where('year', $payslip->year)
            ->where('month', $payslip->month)
            ->where('revision_no', '>', 0)
            ->where('status', PayrollStatus::Finalized->value)
            ->withTotals()
            ->get()
            ->sum(fn (Payroll $p) => $p->totals()['net']);

        return [
            'year' => $payslip->year,
            'month' => $payslip->month,
            ...$payslip->totals(),
            'adjustments_net' => (int) $adjustmentsNet,
        ];
    }

    /** Hanya bawahan langsung. Tidak ada data gaji sama sekali di blok ini. */
    private function team(Employee $manager): array
    {
        $today = now()->toDateString();

        $allIds = Employee::where('manager_id', $manager->id)->pluck('id');
        $activeIds = Employee::where('manager_id', $manager->id)->where('is_active', true)->pluck('id');

        $pending = LeaveRequest::query()
            ->whereIn('employee_id', $allIds)
            ->where('status', LeaveStatus::Pending->value)
            ->with(['employee:id,employee_number,full_name', 'leaveType:id,name'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $onLeave = LeaveRequest::query()
            ->whereIn('employee_id', $activeIds)
            ->where('status', LeaveStatus::Approved->value)
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->with('employee:id,employee_number,full_name')
            ->get();

        $attendance = Attendance::query()
            ->whereIn('employee_id', $activeIds)
            ->where('date', $today)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $counts = $this->attendanceCounts($attendance);

        return [
            'size' => $activeIds->count(),
            'pending_leave' => [
                'count' => $pending->count(),
                'oldest' => $pending->take(5)->map(fn (LeaveRequest $r) => [
                    'id' => $r->id,
                    'employee' => [
                        'id' => $r->employee->id,
                        'employee_number' => $r->employee->employee_number,
                        'full_name' => $r->employee->full_name,
                    ],
                    'leave_type' => $r->leaveType->name,
                    'start_date' => $r->start_date,
                    'end_date' => $r->end_date,
                    'days' => $r->days,
                ])->values(),
            ],
            'on_leave_today' => $onLeave->map(fn (LeaveRequest $r) => [
                'employee' => [
                    'id' => $r->employee->id,
                    'employee_number' => $r->employee->employee_number,
                    'full_name' => $r->employee->full_name,
                ],
                'end_date' => $r->end_date,
            ])->values(),
            'attendance_today' => [
                'recorded' => array_sum($counts),
                'by_status' => $counts,
                'not_recorded' => max(0, $activeIds->count() - array_sum($counts)),
            ],
        ];
    }

    private function hr(int $year, int $month): array
    {
        $today = now()->toDateString();
        $activeCount = Employee::where('is_active', true)->count();

        $byStatus = Employee::where('is_active', true)
            ->selectRaw('employment_status, COUNT(*) as total')
            ->groupBy('employment_status')
            ->pluck('total', 'employment_status');

        $statusCounts = [];
        foreach (EmploymentStatus::cases() as $status) {
            $statusCounts[$status->value] = (int) ($byStatus[$status->value] ?? 0);
        }

        $departments = Department::query()
            ->withCount(['employees as active_count' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('name')
            ->get()
            ->map(fn (Department $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'active_employees' => (int) $d->active_count,
            ])
            ->values();

        $attendance = Attendance::query()
            ->where('date', $today)
            ->whereIn('employee_id', Employee::where('is_active', true)->select('id'))
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $attendanceCounts = $this->attendanceCounts($attendance);

        $pipeline = Candidate::query()
            ->whereIn('stage', [
                CandidateStage::Applied->value,
                CandidateStage::Screening->value,
                CandidateStage::Interview->value,
                CandidateStage::Offer->value,
            ])
            ->selectRaw('stage, COUNT(*) as total')
            ->groupBy('stage')
            ->pluck('total', 'stage');

        $pipelineCounts = [];
        foreach ([CandidateStage::Applied, CandidateStage::Screening, CandidateStage::Interview, CandidateStage::Offer] as $stage) {
            $pipelineCounts[$stage->value] = (int) ($pipeline[$stage->value] ?? 0);
        }

        return [
            'employees' => [
                'active' => $activeCount,
                'inactive' => Employee::where('is_active', false)->count(),
                'by_employment_status' => $statusCounts,
                'by_department' => $departments,
            ],
            'leave' => [
                'pending_count' => LeaveRequest::where('status', LeaveStatus::Pending->value)->count(),
                'on_leave_today' => LeaveRequest::query()
                    ->where('status', LeaveStatus::Approved->value)
                    ->where('start_date', '<=', $today)
                    ->where('end_date', '>=', $today)
                    ->count(),
            ],
            'attendance_today' => [
                'recorded' => array_sum($attendanceCounts),
                'by_status' => $attendanceCounts,
                'not_recorded' => max(0, $activeCount - array_sum($attendanceCounts)),
            ],
            'recruitment' => [
                'open_openings' => JobOpening::where('status', JobOpeningStatus::Open->value)->count(),
                'pipeline' => $pipelineCounts,
                'accepted_awaiting_conversion' => Candidate::query()
                    ->where('stage', CandidateStage::Accepted->value)
                    ->whereNull('converted_employee_id')
                    ->count(),
            ],
            // Cuma jumlah proses. Nominal gaji tidak ditampilkan di dashboard HR.
            'payroll' => $this->payrollProcess($year, $month),
        ];
    }

    private function finance(int $year, int $month): array
    {
        $base = PayrollItem::query()
            ->join('payrolls', 'payrolls.id', '=', 'payroll_items.payroll_id')
            ->where('payrolls.year', $year)
            ->where('payrolls.month', $month)
            ->where('payrolls.status', PayrollStatus::Finalized->value);

        // Definisi pendapatan vs potongan tetap satu sumber: enum PayrollItemType.
        // Koreksi adalah selisih, jadi payroll asli dan koreksinya dijumlahkan.
        $gross = (int) (clone $base)->whereIn('payroll_items.type', PayrollItemType::earnings())->sum('payroll_items.amount');
        $deductions = (int) (clone $base)->whereIn('payroll_items.type', PayrollItemType::deductions())->sum('payroll_items.amount');

        return $this->payrollProcess($year, $month) + [
            'finalized_totals' => [
                'gross' => $gross,
                'deductions' => $deductions,
                'net' => $gross - $deductions,
            ],
        ];
    }

    /** draft/finalized menghitung semua baris (asli + koreksi). missing = karyawan aktif tanpa payroll asli. */
    private function payrollProcess(int $year, int $month): array
    {
        $rows = Payroll::query()
            ->where('year', $year)
            ->where('month', $month)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $withOriginal = Payroll::query()
            ->where('year', $year)
            ->where('month', $month)
            ->where('revision_no', 0)
            ->select('employee_id');

        return [
            'period' => ['year' => $year, 'month' => $month],
            'draft' => (int) ($rows[PayrollStatus::Draft->value] ?? 0),
            'finalized' => (int) ($rows[PayrollStatus::Finalized->value] ?? 0),
            'missing' => Employee::where('is_active', true)->whereNotIn('id', $withOriginal)->count(),
        ];
    }

    private function admin(?Employee $own): array
    {
        $byRole = User::query()
            ->selectRaw('role, COUNT(*) as total')
            ->groupBy('role')
            ->pluck('total', 'role');

        $roleCounts = [];
        foreach (Role::cases() as $role) {
            $roleCounts[$role->value] = (int) ($byRole[$role->value] ?? 0);
        }

        // Karyawan tanpa atasan diputuskan Super Admin, kecuali pengajuannya sendiri.
        $awaiting = LeaveRequest::query()
            ->where('status', LeaveStatus::Pending->value)
            ->whereIn('employee_id', Employee::whereNull('manager_id')->select('id'))
            ->when($own, fn ($q) => $q->where('employee_id', '!=', $own->id))
            ->count();

        return [
            'accounts' => [
                'total' => User::count(),
                'active' => User::where('is_active', true)->count(),
                'locked' => User::where('is_active', false)->count(),
                'by_role' => $roleCounts,
            ],
            'employees_without_account' => Employee::where('is_active', true)->whereNull('user_id')->count(),
            'leave_awaiting_decision' => $awaiting,
        ];
    }

    private function attendanceCounts($counts): array
    {
        $result = [];

        foreach (AttendanceStatus::cases() as $status) {
            $result[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        return $result;
    }
}
