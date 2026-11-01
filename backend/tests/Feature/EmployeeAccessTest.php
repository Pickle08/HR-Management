<?php

namespace Tests\Feature;

use App\Enums\EmploymentStatus;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmployeeAccessTest extends TestCase
{
    use RefreshDatabase;

    private Department $dept;
    private Position $pos;
    private User $hr;
    private User $finance;
    private User $atasanUser;
    private User $karyawanUser;
    private User $otherAtasanUser;
    private Employee $atasan;
    private Employee $member;
    private Employee $member2;
    private Employee $otherAtasan;
    private Employee $otherMember;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dept = Department::create(['name' => 'Operasional']);
        $this->pos = Position::create(['name' => 'Staff']);

        $this->hr = $this->makeUser(Role::Hr, 'hr@t.test');
        $this->finance = $this->makeUser(Role::Finance, 'fin@t.test');
        $this->atasanUser = $this->makeUser(Role::Atasan, 'atasan@t.test');
        $this->karyawanUser = $this->makeUser(Role::Karyawan, 'kary@t.test');
        $this->otherAtasanUser = $this->makeUser(Role::Atasan, 'atasan2@t.test');

        // Tim 1: E1 punya bawahan E2 dan E3. Tim 2: E4 punya bawahan E5.
        $this->atasan = $this->makeEmployee('E1', 'Atasan Satu', $this->atasanUser);
        $this->member = $this->makeEmployee('E2', 'Anggota Satu', $this->karyawanUser, $this->atasan);
        $this->member2 = $this->makeEmployee('E3', 'Anggota Dua', null, $this->atasan);
        $this->otherAtasan = $this->makeEmployee('E4', 'Atasan Dua', $this->otherAtasanUser);
        $this->otherMember = $this->makeEmployee('E5', 'Anggota Tim Lain', null, $this->otherAtasan);
    }

    private function makeUser(Role $role, string $email): User
    {
        return User::forceCreate([
            'name' => $role->value,
            'email' => $email,
            'password' => 'password',
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function makeEmployee(string $number, string $name, ?User $user = null, ?Employee $manager = null): Employee
    {
        return Employee::forceCreate([
            'user_id' => $user?->id,
            'manager_id' => $manager?->id,
            'department_id' => $this->dept->id,
            'position_id' => $this->pos->id,
            'employee_number' => $number,
            'full_name' => $name,
            'nik' => '3201010101010001',
            'phone' => '08123456789',
            'address' => 'Jl. Contoh No. 1',
            'hire_date' => '2024-01-15',
            'employment_status' => EmploymentStatus::Tetap,
            'is_active' => true,
        ]);
    }

    /** Guard Sanctum nyimpen user dari request sebelumnya, jadi di-reset tiap ganti user. */
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
            'employee_number' => 'E100',
            'full_name' => 'Karyawan Baru',
            'nik' => '3201010101010002',
            'phone' => '08111111111',
            'hire_date' => '2025-01-10',
            'employment_status' => 'kontrak',
        ], $override);
    }

    private function numbers($response): array
    {
        return collect($response->json('data'))->pluck('employee_number')->all();
    }

    public function test_hr_sees_all_employees(): void
    {
        $this->actAs($this->hr)->getJson('/api/employees')
            ->assertOk()
            ->assertJsonCount(5, 'data');
    }

    public function test_atasan_list_contains_only_self_and_own_team(): void
    {
        $res = $this->actAs($this->atasanUser)->getJson('/api/employees')->assertOk();

        $this->assertEqualsCanonicalizing(['E1', 'E2', 'E3'], $this->numbers($res));
    }

    public function test_karyawan_list_contains_only_self(): void
    {
        $res = $this->actAs($this->karyawanUser)->getJson('/api/employees')->assertOk();

        $this->assertSame(['E2'], $this->numbers($res));
    }

    public function test_finance_and_guest_cannot_list_employees(): void
    {
        $this->getJson('/api/employees')->assertUnauthorized();

        $this->actAs($this->finance)->getJson('/api/employees')->assertForbidden();
    }

    public function test_atasan_cannot_view_employee_outside_own_team(): void
    {
        $this->actAs($this->atasanUser)
            ->getJson("/api/employees/{$this->otherMember->id}")
            ->assertForbidden();

        $this->actAs($this->atasanUser)
            ->getJson("/api/employees/{$this->otherAtasan->id}")
            ->assertForbidden();
    }

    public function test_atasan_can_view_own_subordinate(): void
    {
        $this->actAs($this->atasanUser)
            ->getJson("/api/employees/{$this->member2->id}")
            ->assertOk()
            ->assertJsonPath('data.employee_number', 'E3');
    }

    public function test_karyawan_can_view_self_but_not_colleague(): void
    {
        $this->actAs($this->karyawanUser)
            ->getJson("/api/employees/{$this->member->id}")
            ->assertOk();

        $this->actAs($this->karyawanUser)
            ->getJson("/api/employees/{$this->member2->id}")
            ->assertForbidden();
    }

    public function test_sensitive_fields_visibility(): void
    {
        $url = "/api/employees/{$this->member->id}";

        $this->actAs($this->hr)->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.nik', '3201010101010001');

        $this->actAs($this->atasanUser)->getJson($url)
            ->assertOk()
            ->assertJsonMissingPath('data.nik')
            ->assertJsonMissingPath('data.phone')
            ->assertJsonMissingPath('data.address');

        $this->actAs($this->karyawanUser)->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.nik', '3201010101010001');
    }

    public function test_hr_can_create_employee_and_sensitive_fields_are_encrypted_at_rest(): void
    {
        $this->actAs($this->hr)->postJson('/api/employees', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.employee_number', 'E100');

        $raw = DB::table('employees')->where('employee_number', 'E100')->first();

        $this->assertNotSame('3201010101010002', $raw->nik);
        $this->assertStringNotContainsString(
            '3201010101010002',
            json_encode(AuditLog::all()->toArray())
        );
    }

    public function test_non_hr_cannot_create_employee(): void
    {
        foreach ([$this->atasanUser, $this->karyawanUser, $this->finance] as $user) {
            $this->actAs($user)->postJson('/api/employees', $this->payload())->assertForbidden();
        }

        $this->assertDatabaseMissing('employees', ['employee_number' => 'E100']);
    }

    public function test_user_id_cannot_be_set_through_api(): void
    {
        $this->actAs($this->hr)
            ->postJson('/api/employees', $this->payload(['user_id' => $this->finance->id]))
            ->assertCreated();

        $this->assertDatabaseHas('employees', ['employee_number' => 'E100', 'user_id' => null]);
    }

    public function test_duplicate_employee_number_is_rejected(): void
    {
        $this->actAs($this->hr)
            ->postJson('/api/employees', $this->payload(['employee_number' => 'E1']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('employee_number');
    }

    public function test_manager_cycle_is_rejected(): void
    {
        // E1 mencoba diberi atasan E2, padahal E2 adalah bawahannya sendiri.
        $this->actAs($this->hr)
            ->putJson("/api/employees/{$this->atasan->id}", $this->payload([
                'employee_number' => 'E1',
                'manager_id' => $this->member->id,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('manager_id');
    }

    public function test_update_is_audited_with_actor_and_masked_nik(): void
    {
        $this->actAs($this->hr)
            ->putJson("/api/employees/{$this->member->id}", $this->payload([
                'employee_number' => 'E2',
                'full_name' => 'Nama Baru',
                'manager_id' => $this->atasan->id,
            ]))
            ->assertOk();

        $log = AuditLog::where('action', 'employee.updated')
            ->where('auditable_id', $this->member->id)
            ->firstOrFail();

        $this->assertSame($this->hr->id, $log->user_id);
        $this->assertSame('Anggota Satu', $log->old_values['full_name']);
        $this->assertSame('Nama Baru', $log->new_values['full_name']);
        $this->assertSame('[disembunyikan]', $log->new_values['nik']);
    }

    public function test_only_hr_can_deactivate_employee(): void
    {
        $url = "/api/employees/{$this->member->id}/deactivate";

        $this->actAs($this->atasanUser)->postJson($url)->assertForbidden();
        $this->assertTrue($this->member->fresh()->is_active);

        $this->actAs($this->hr)->postJson($url)->assertOk();
        $this->assertFalse($this->member->fresh()->is_active);
    }
}
