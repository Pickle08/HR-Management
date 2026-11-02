<?php

namespace Tests\Feature;

use App\Enums\EmploymentStatus;
use App\Enums\Role;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagerAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Department $dept;
    private Position $pos;
    private User $hr;
    private Employee $target;
    private Employee $atasan;
    private Employee $karyawanManager;
    private Employee $noAccount;
    private Employee $lockedAccount;
    private Employee $inactiveEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dept = Department::create(['name' => 'Operasional']);
        $this->pos = Position::create(['name' => 'Staff']);

        $this->hr = $this->makeUser(Role::Hr, 'hr@t.test');

        $this->target = $this->makeEmployee('T1');
        $this->atasan = $this->makeEmployee('A1', $this->makeUser(Role::Atasan, 'a1@t.test'));
        $this->karyawanManager = $this->makeEmployee('K1', $this->makeUser(Role::Karyawan, 'k1@t.test'));
        $this->noAccount = $this->makeEmployee('N0');
        $this->lockedAccount = $this->makeEmployee('L1', $this->makeUser(Role::Atasan, 'l1@t.test', false));
        $this->inactiveEmployee = $this->makeEmployee('I1', $this->makeUser(Role::Atasan, 'i1@t.test'), false);
    }

    private function makeUser(Role $role, string $email, bool $active = true): User
    {
        return User::forceCreate([
            'name' => $role->value,
            'email' => $email,
            'password' => 'password',
            'role' => $role,
            'is_active' => $active,
        ]);
    }

    private function makeEmployee(string $number, ?User $user = null, bool $active = true): Employee
    {
        return Employee::forceCreate([
            'user_id' => $user?->id,
            'department_id' => $this->dept->id,
            'position_id' => $this->pos->id,
            'employee_number' => $number,
            'full_name' => "Karyawan {$number}",
            'hire_date' => '2024-01-15',
            'employment_status' => EmploymentStatus::Tetap,
            'is_active' => $active,
        ]);
    }

    private function actAs(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user);
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'department_id' => $this->dept->id,
            'position_id' => $this->pos->id,
            'employee_number' => 'T1',
            'full_name' => 'Karyawan T1',
            'hire_date' => '2024-01-15',
            'employment_status' => 'tetap',
        ], $override);
    }

    public function test_manager_must_be_an_active_atasan_with_an_active_account(): void
    {
        $invalid = [$this->karyawanManager, $this->noAccount, $this->lockedAccount, $this->inactiveEmployee];

        foreach ($invalid as $manager) {
            $this->actAs($this->hr)
                ->putJson("/api/employees/{$this->target->id}", $this->payload(['manager_id' => $manager->id]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('manager_id');

            $this->actAs($this->hr)
                ->postJson('/api/employees', $this->payload(['employee_number' => 'N1', 'manager_id' => $manager->id]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('manager_id');
        }

        $this->assertNull($this->target->fresh()->manager_id);
        $this->assertDatabaseMissing('employees', ['employee_number' => 'N1']);
    }

    public function test_valid_manager_is_accepted_and_can_be_cleared(): void
    {
        $this->actAs($this->hr)
            ->putJson("/api/employees/{$this->target->id}", $this->payload(['manager_id' => $this->atasan->id]))
            ->assertOk()
            ->assertJsonPath('data.manager_id', $this->atasan->id);

        $this->actAs($this->hr)
            ->putJson("/api/employees/{$this->target->id}", $this->payload(['manager_id' => null]))
            ->assertOk()
            ->assertJsonPath('data.manager_id', null);

        $this->actAs($this->hr)
            ->postJson('/api/employees', $this->payload(['employee_number' => 'N1', 'manager_id' => $this->atasan->id]))
            ->assertCreated()
            ->assertJsonPath('data.manager_id', $this->atasan->id);
    }
}
