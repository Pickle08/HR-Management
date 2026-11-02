<?php

namespace App\Rules;

use App\Enums\Role;
use App\Models\Employee;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ActiveManager implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $manager = Employee::with('user')->find($value);

        $valid = $manager
            && $manager->is_active
            && $manager->user?->is_active
            && $manager->user->role === Role::Atasan;

        if (! $valid) {
            $fail('Atasan harus karyawan aktif yang punya akun aktif dengan peran atasan.');
        }
    }
}
