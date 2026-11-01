<?php

namespace App\Services;

use App\Enums\LeaveStatus;
use App\Enums\Role;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveService
{
    /** Status yang "memegang" jatah. Rejected dan cancelled melepasnya. */
    private const COMMITTED = ['pending', 'approved'];

    public function submit(Employee $employee, array $data): LeaveRequest
    {
        $start = CarbonImmutable::parse($data['start_date']);
        $end = CarbonImmutable::parse($data['end_date']);

        if ($start->year !== $end->year) {
            throw ValidationException::withMessages([
                'end_date' => ['Pengajuan tidak boleh melintasi pergantian tahun. Pisahkan jadi dua pengajuan.'],
            ]);
        }

        $days = $this->workingDays($start, $end);

        if ($days === 0) {
            throw ValidationException::withMessages([
                'start_date' => ['Rentang tanggal tidak berisi hari kerja (Senin sampai Jumat).'],
            ]);
        }

        return DB::transaction(function () use ($employee, $data, $start, $end, $days) {
            // Kunci baris karyawan: pengajuan bersamaan dari karyawan yang sama diproses
            // bergantian, jadi cek tumpang tindih dan cek jatah di bawah tidak bisa
            // dilewati lewat dua request yang balapan.
            Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();

            $overlaps = LeaveRequest::query()
                ->where('employee_id', $employee->id)
                ->whereIn('status', self::COMMITTED)
                ->where('start_date', '<=', $end->toDateString())
                ->where('end_date', '>=', $start->toDateString())
                ->exists();

            if ($overlaps) {
                throw ValidationException::withMessages([
                    'start_date' => ['Tanggal bertumpuk dengan pengajuan lain yang masih aktif.'],
                ]);
            }

            $balance = LeaveBalance::where([
                'employee_id' => $employee->id,
                'leave_type_id' => $data['leave_type_id'],
                'year' => $start->year,
            ])->first();

            if (! $balance) {
                throw ValidationException::withMessages([
                    'leave_type_id' => ['Jatah cuti untuk jenis dan tahun ini belum diatur. Hubungi HR.'],
                ]);
            }

            $committed = $this->committedDays($employee->id, (int) $data['leave_type_id'], $start->year);

            if ($committed + $days > $balance->entitled_days) {
                $remaining = max(0, $balance->entitled_days - $committed);

                throw ValidationException::withMessages([
                    'leave_type_id' => ["Sisa jatah tidak cukup. Diminta {$days} hari, sisa {$remaining} hari."],
                ]);
            }

            $leave = new LeaveRequest(Arr::only($data, ['leave_type_id', 'start_date', 'end_date', 'reason']));
            $leave->forceFill([
                'employee_id' => $employee->id,
                'year' => $start->year,
                'days' => $days,
                'status' => LeaveStatus::Pending,
            ])->save();

            return $leave;
        });
    }

    public function decide(LeaveRequest $leave, User $actor, LeaveStatus $outcome, ?string $note): LeaveRequest
    {
        return DB::transaction(function () use ($leave, $actor, $outcome, $note) {
            $locked = LeaveRequest::whereKey($leave->id)->lockForUpdate()->firstOrFail();

            // Keputusan final: yang sudah diputuskan atau dibatalkan tidak bisa ditimpa.
            if ($locked->status !== LeaveStatus::Pending) {
                abort(409, 'Pengajuan ini sudah diputuskan atau dibatalkan.');
            }

            if ($outcome === LeaveStatus::Approved) {
                $balance = LeaveBalance::where([
                    'employee_id' => $locked->employee_id,
                    'leave_type_id' => $locked->leave_type_id,
                    'year' => $locked->year,
                ])->first();

                // Angka ini sudah mencakup pengajuan ini sendiri (statusnya masih pending).
                $committed = $this->committedDays($locked->employee_id, $locked->leave_type_id, $locked->year);

                if (! $balance || $committed > $balance->entitled_days) {
                    throw ValidationException::withMessages([
                        'leave_type_id' => ['Jatah cuti karyawan berkurang dan tidak cukup untuk pengajuan ini. Hubungi HR.'],
                    ]);
                }
            }

            $locked->forceFill([
                'status' => $outcome,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ])->save();

            return $locked;
        });
    }

    public function cancel(LeaveRequest $leave, User $actor, ?string $note): LeaveRequest
    {
        $isHrAdmin = in_array($actor->role, [Role::Hr, Role::SuperAdmin], true);

        return DB::transaction(function () use ($leave, $actor, $note, $isHrAdmin) {
            $locked = LeaveRequest::whereKey($leave->id)->lockForUpdate()->firstOrFail();

            // Karyawan hanya bisa membatalkan yang masih menunggu; yang sudah disetujui lewat HR.
            $cancellable = $isHrAdmin
                ? [LeaveStatus::Pending, LeaveStatus::Approved]
                : [LeaveStatus::Pending];

            if (! in_array($locked->status, $cancellable, true)) {
                abort(409, 'Pengajuan ini tidak bisa dibatalkan.');
            }

            // Kolom decided_* sengaja tidak disentuh: riwayat persetujuan tetap utuh.
            $locked->forceFill([
                'status' => LeaveStatus::Cancelled,
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
                'cancellation_note' => $note,
            ])->save();

            return $locked;
        });
    }

    private function committedDays(int $employeeId, int $leaveTypeId, int $year): int
    {
        return (int) LeaveRequest::query()
            ->where('employee_id', $employeeId)
            ->where('leave_type_id', $leaveTypeId)
            ->where('year', $year)
            ->whereIn('status', self::COMMITTED)
            ->sum('days');
    }

    /** Hari kerja Senin sampai Jumat. Libur nasional belum diperhitungkan. */
    private function workingDays(CarbonInterface $start, CarbonInterface $end): int
    {
        $days = 0;

        foreach (CarbonPeriod::create($start, $end) as $date) {
            if ($date->isWeekday()) {
                $days++;
            }
        }

        return $days;
    }
}
