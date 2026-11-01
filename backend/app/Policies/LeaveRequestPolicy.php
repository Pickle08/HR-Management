<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\LeaveRequest;
use App\Models\User;

/**
 * Policy ini cuma menjawab "SIAPA boleh". Aturan "KONDISI apa" (masih pending,
 * bisa dibatalkan, dst.) dicek ulang di LeaveService di dalam transaksi dengan
 * row lock, supaya jawabannya tidak basi dan hasilnya 409, bukan 403 yang menyesatkan.
 */
class LeaveRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role !== Role::Finance;
    }

    public function view(User $user, LeaveRequest $leave): bool
    {
        return $user->can('view', $leave->employee);
    }

    /** Hanya karyawan aktif yang punya data karyawan yang bisa mengajukan, dan hanya untuk dirinya. */
    public function create(User $user): bool
    {
        return $user->employee?->is_active === true;
    }

    public function decide(User $user, LeaveRequest $leave): bool
    {
        $ownEmployeeId = $user->employee?->id;

        // Tidak ada yang boleh memutuskan pengajuannya sendiri.
        if ($ownEmployeeId === $leave->employee_id) {
            return false;
        }

        $managerId = $leave->employee->manager_id;

        return match ($user->role) {
            // Atasan langsung.
            Role::Atasan => $ownEmployeeId !== null && $managerId === $ownEmployeeId,
            // Fallback: karyawan tanpa atasan diputuskan Super Admin.
            Role::SuperAdmin => $managerId === null,
            // HR memantau, bukan memutuskan.
            default => false,
        };
    }

    public function cancel(User $user, LeaveRequest $leave): bool
    {
        return $user->employee?->id === $leave->employee_id
            || in_array($user->role, [Role::Hr, Role::SuperAdmin], true);
    }
}
