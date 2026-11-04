<?php

namespace Database\Seeders;

use App\Enums\AttendanceStatus;
use App\Enums\LeaveStatus;
use App\Enums\PayrollStatus;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Payroll;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Data contoh supaya dashboard tidak kosong saat mengembangkan frontend.
 * Manual, hanya di local: php artisan db:seed --class=DevSampleDataSeeder
 */
class DevSampleDataSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->error('Hanya untuk environment local.');

            return;
        }

        $kiki = Employee::where('employee_number', 'EMP-005')->first();
        $dewi = Employee::where('employee_number', 'EMP-006')->first();
        $hr = User::where('email', 'hr@hrm.test')->first();

        if (! $kiki || ! $dewi || ! $hr) {
            $this->command?->error('Jalankan DatabaseSeeder dulu: EMP-005, EMP-006, dan akun hr@hrm.test tidak ditemukan.');

            return;
        }

        // Jatah cuti tahun ini untuk semua karyawan aktif.
        $type = LeaveType::firstOrCreate(['name' => 'Cuti Tahunan'], ['annual_quota' => 12, 'is_active' => true]);

        Employee::where('is_active', true)->each(fn (Employee $e) => LeaveBalance::firstOrCreate(
            ['employee_id' => $e->id, 'leave_type_id' => $type->id, 'year' => now()->year],
            ['entitled_days' => $type->annual_quota],
        ));

        // Absensi hari ini.
        foreach ([[$kiki, AttendanceStatus::Hadir, '08:00', '17:00'], [$dewi, AttendanceStatus::Izin, null, null]] as [$employee, $status, $in, $out]) {
            Attendance::firstOrCreate(
                ['employee_id' => $employee->id, 'date' => now()->toDateString()],
                ['status' => $status, 'check_in' => $in, 'check_out' => $out],
            );
        }

        // Kiki mengajukan cuti Senin-Selasa depan (menunggu). Dewi sedang cuti (disetujui).
        $monday = now()->next('Monday');

        $this->leave($kiki, LeaveStatus::Pending, $monday->toDateString(), $monday->copy()->addDay()->toDateString(), 2, $type);
        $this->leave($dewi, LeaveStatus::Approved, now()->subDay()->toDateString(), now()->addDay()->toDateString(), 3, $type);

        // Slip bulan lalu Kiki: bruto 8.500.000, potongan 500.000, neto 8.000.000.
        $prev = now()->subMonthNoOverflow();

        $hasPayroll = Payroll::where([
            'employee_id' => $kiki->id, 'year' => $prev->year, 'month' => $prev->month, 'revision_no' => 0,
        ])->exists();

        if (! $hasPayroll) {
            $payroll = new Payroll();
            $payroll->forceFill([
                'employee_id' => $kiki->id,
                'year' => $prev->year,
                'month' => $prev->month,
                'revision_no' => 0,
                'status' => PayrollStatus::Draft,
                'prepared_by' => $hr->id,
            ])->save();

            foreach ([
                ['type' => 'basic_salary', 'amount' => 8_000_000],
                ['type' => 'allowance', 'label' => 'Transport', 'amount' => 500_000],
                ['type' => 'bpjs', 'amount' => 300_000],
                ['type' => 'pph21', 'amount' => 200_000],
            ] as $item) {
                $payroll->items()->create($item);
            }

            Payroll::whereKey($payroll->id)->update([
                'status' => 'finalized',
                'finalized_by' => User::where('email', 'finance@hrm.test')->value('id'),
                'finalized_at' => now(),
            ]);
        }

        $this->command?->info('Data contoh siap.');
    }

    private function leave(Employee $employee, LeaveStatus $status, string $start, string $end, int $days, LeaveType $type): void
    {
        if (LeaveRequest::where('employee_id', $employee->id)->where('start_date', $start)->exists()) {
            return;
        }

        LeaveRequest::forceCreate([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'year' => (int) substr($start, 0, 4),
            'start_date' => $start,
            'end_date' => $end,
            'days' => $days,
            'status' => $status,
        ]);
    }
}
