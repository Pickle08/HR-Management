<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

class UserPolicy
{
    public function manage(User $user): bool
    {
        return $user->role === Role::SuperAdmin;
    }
}
