<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

class LeaveTypePolicy
{
    public function manage(User $user): bool
    {
        return in_array($user->role, [Role::Hr, Role::SuperAdmin], true);
    }
}
