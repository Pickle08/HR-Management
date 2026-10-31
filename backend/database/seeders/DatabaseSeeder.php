<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (!app()->environment('local')) {
            return;
        }

        foreach (Role::cases() as $role) {
            User::forceCreate([
                'name' => ucwords(str_replace('_', ' ', $role->value)),
                'email' => $role->value . '@hrm.test',
                'password' => 'password',
                'role' => $role,
                'is_active' => true,
            ]);
        }
    }
}
