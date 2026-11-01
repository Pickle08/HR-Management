<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Enums\EmploymentStatus;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
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
        // Data contoh cuma buat dev. Di production akun awal dibikin terpisah (Tahap 5).
        if (!app()->environment('local')) {
            return;
        }

        $departments = collect(['Human Resources', 'Finance', 'Operasional', 'Teknologi Informasi'])
            ->mapWithKeys(fn($name) => [$name => Department::create(['name' => $name])]);

        $positions = collect(['Direktur', 'Manager', 'HR Officer', 'Finance Officer', 'Staff'])
            ->mapWithKeys(fn($name) => [$name => Position::create(['name' => $name])]);

        $users = [];
        foreach (Role::cases() as $role) {
            $users[$role->value] = User::forceCreate([
                'name' => ucwords(str_replace('_', ' ', $role->value)),
                'email' => $role->value . '@hrm.test',
                'password' => 'password',
                'role' => $role,
                'is_active' => true,
            ]);
        }

        $make = fn(string $number, string $name, string $dept, string $pos, ?User $user = null, ?Employee $manager = null) => Employee::forceCreate([
            'user_id' => $user?->id,
            'manager_id' => $manager?->id,
            'department_id' => $departments[$dept]->id,
            'position_id' => $positions[$pos]->id,
            'employee_number' => $number,
            'full_name' => $name,
            'hire_date' => '2024-01-15',
            'employment_status' => EmploymentStatus::Tetap,
            'is_active' => true,
        ]);

        $atasan = $make('EMP-001', 'Budi Atasan', 'Operasional', 'Manager', $users['atasan']);
        $make('EMP-002', 'Sari Super Admin', 'Teknologi Informasi', 'Direktur', $users['super_admin']);
        $make('EMP-003', 'Hana HR', 'Human Resources', 'HR Officer', $users['hr']);
        $make('EMP-004', 'Fajar Finance', 'Finance', 'Finance Officer', $users['finance']);
        $make('EMP-005', 'Kiki Karyawan', 'Operasional', 'Staff', $users['karyawan'], $atasan);
        $make('EMP-006', 'Dewi Staff', 'Operasional', 'Staff', null, $atasan);
        $make('EMP-007', 'Rudi Tim Lain', 'Teknologi Informasi', 'Staff'); // bukan bawahan atasan
    }
}
