<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

class LeaveBalancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role !== Role::Finance;
    }

    public function manage(User $user): bool
    {
        return in_array($user->role, [Role::Hr, Role::SuperAdmin], true);
    }
}
