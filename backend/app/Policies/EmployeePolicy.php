<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Employee;
use App\Models\User;

class EmployeePolicy
{
    private function isHrAdmin(User $user): bool
    {
        return in_array($user->role, [Role::Hr, Role::SuperAdmin], true);
    }

    public function viewAny(User $user): bool
    {
        return $user->role !== Role::Finance;
    }

    public function view(User $user, Employee $employee): bool
    {
        if ($this->isHrAdmin($user)) {
            return true;
        }

        $own = $user->employee;

        if (! $own) {
            return false;
        }

        if ($own->is($employee)) {
            return true;
        }

        return $user->role === Role::Atasan && $employee->manager_id === $own->id;
    }

    /** nik, phone, tanggal lahir, alamat. */
    public function viewSensitive(User $user, Employee $employee): bool
    {
        return $this->isHrAdmin($user) || $user->employee?->is($employee) === true;
    }

    public function create(User $user): bool
    {
        return $this->isHrAdmin($user);
    }

    public function update(User $user, Employee $employee): bool
    {
        return $this->isHrAdmin($user);
    }
}
