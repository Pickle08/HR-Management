<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Attendance;
use App\Models\User;

class AttendancePolicy
{
    private function isHrAdmin(User $user): bool
    {
        return in_array($user->role, [Role::Hr, Role::SuperAdmin], true);
    }

    public function viewAny(User $user): bool
    {
        return $user->role !== Role::Finance;
    }

    // Boleh lihat absensi = boleh lihat karyawannya (aturan tim ada di EmployeePolicy).
    public function view(User $user, Attendance $attendance): bool
    {
        return $user->can('view', $attendance->employee);
    }

    public function create(User $user): bool
    {
        return $this->isHrAdmin($user);
    }

    public function update(User $user, Attendance $attendance): bool
    {
        return $this->isHrAdmin($user);
    }
}
