<?php

namespace App\Policies;

use App\Enums\PayrollStatus;
use App\Enums\Role;
use App\Models\Payroll;
use App\Models\User;

/**
 * Policy menjawab "SIAPA boleh". "KONDISI apa" (masih draft, sudah final, dst.)
 * dicek ulang di PayrollService di dalam transaksi dengan row lock.
 */
class PayrollPolicy
{
    private function isOwn(User $user, Payroll $payroll): bool
    {
        return $user->employee?->id === $payroll->employee_id;
    }

    public function viewAny(User $user): bool
    {
        return in_array($user->role, [Role::Hr, Role::Finance, Role::Atasan, Role::Karyawan], true);
    }

    public function view(User $user, Payroll $payroll): bool
    {
        return match ($user->role) {
            Role::Hr, Role::Finance => true,
            Role::Atasan, Role::Karyawan => $this->isOwn($user, $payroll)
                && $payroll->status === PayrollStatus::Finalized,
            default => false,
        };
    }

    public function create(User $user): bool
    {
        return $user->role === Role::Hr;
    }

    public function update(User $user, Payroll $payroll): bool
    {
        return in_array($user->role, [Role::Hr, Role::Finance], true) && ! $this->isOwn($user, $payroll);
    }

    public function delete(User $user, Payroll $payroll): bool
    {
        return $user->role === Role::Hr && ! $this->isOwn($user, $payroll);
    }

    public function finalize(User $user, Payroll $payroll): bool
    {
        return $user->role === Role::Finance && ! $this->isOwn($user, $payroll);
    }

    public function adjust(User $user, Payroll $payroll): bool
    {
        return $user->role === Role::Hr && ! $this->isOwn($user, $payroll);
    }
}
