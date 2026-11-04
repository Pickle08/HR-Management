<?php

namespace Tests\Feature;

use App\Enums\EmploymentStatus;
use App\Enums\Role;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmployeeRegionTest extends TestCase
{
    use RefreshDatabase;

    private Department $dept;
    private Position $pos;
    private User $hr;
    private User $superUser;
    private User $finance;
    private User $atasanUser;
    private User $karyawanUser;
    private Employee $atasanEmp;
    private Employee $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dept = Department::create(['name' => 'Operasional']);
        $this->pos = Position::create(['name' => 'Staff']);

        $this->hr = $this->makeUser(Role::Hr, 'hr@t.test');
        $this->superUser = $this->makeUser(Role::SuperAdmin, 'super@t.test');
        $this->finance = $this->makeUser(Role::Finance, 'fin@t.test');
        $this->atasanUser = $this->makeUser(Role::Atasan, 'atasan@t.test');
        $this->karyawanUser = $this->makeUser(Role::Karyawan, 'kary@t.test');

        $this->atasanEmp = $this->makeEmployee('E1', $this->atasanUser, null, 'Budi Atasan');
        $this->member = $this->makeEmployee('E2', $this->karyawanUser, $this->atasanEmp, 'Anggota Satu');
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

    private function makeEmployee(string $number, ?User $user = null, ?Employee $manager = null, ?string $name = null, bool $active = true): Employee
    {
        return Employee::forceCreate([
            'user_id' => $user?->id,
            'manager_id' => $manager?->id,
            'department_id' => $this->dept->id,
            'position_id' => $this->pos->id,
            'employee_number' => $number,
            'full_name' => $name ?? "Karyawan {$number}",
            'hire_date' => '2024-01-15',
            'employment_status' => EmploymentStatus::Tetap,
            'is_active' => $active,
        ]);
    }

    /** Guard Sanctum menyimpan user dari request sebelumnya, jadi di-reset tiap ganti user. */
    private function actAs(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user);
    }

    private function seedRegions(): void
    {
        DB::table('provinces')->insert([
            ['code' => '32', 'name' => 'Jawa Barat'],
            ['code' => '35', 'name' => 'Jawa Timur'],
        ]);

        DB::table('regencies')->insert([
            ['code' => '32.73', 'province_code' => '32', 'name' => 'Kota Bandung'],
            ['code' => '35.77', 'province_code' => '35', 'name' => 'Kota Madiun'],
        ]);

        DB::table('districts')->insert([
            ['code' => '32.73.01', 'regency_code' => '32.73', 'name' => 'Sukasari'],
            ['code' => '35.77.01', 'regency_code' => '35.77', 'name' => 'Manguharjo'],
        ]);

        DB::table('villages')->insert([
            ['code' => '32.73.01.1002', 'district_code' => '32.73.01', 'name' => 'Gegerkalong'],
            ['code' => '35.77.01.1001', 'district_code' => '35.77.01', 'name' => 'Winongo'],
        ]);
    }

    private function chain(): array
    {
        return [
            'province_code' => '32',
            'regency_code' => '32.73',
            'district_code' => '32.73.01',
            'village_code' => '32.73.01.1002',
        ];
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'department_id' => $this->dept->id,
            'position_id' => $this->pos->id,
            'employee_number' => 'E2',
            'full_name' => 'Anggota Satu',
            'hire_date' => '2024-01-15',
            'employment_status' => 'tetap',
            'manager_id' => $this->atasanEmp->id,
        ], $override);
    }

    public function test_region_is_saved_and_shown_only_to_sensitive_viewers(): void
    {
        $this->seedRegions();

        $this->actAs($this->hr)
            ->putJson("/api/employees/{$this->member->id}", $this->payload($this->chain() + ['address' => 'Jl. Contoh 1']))
            ->assertOk()
            ->assertJsonPath('data.province_code', '32')
            ->assertJsonPath('data.village_code', '32.73.01.1002')
            ->assertJsonPath('data.region.province', 'Jawa Barat')
            ->assertJsonPath('data.region.regency', 'Kota Bandung')
            ->assertJsonPath('data.region.district', 'Sukasari')
            ->assertJsonPath('data.region.village', 'Gegerkalong');

        $this->assertSame('32.73.01.1002', $this->member->fresh()->village_code);

        // Pemilik data melihat alamatnya sendiri.
        $this->actAs($this->karyawanUser)->getJson("/api/employees/{$this->member->id}")
            ->assertOk()
            ->assertJsonPath('data.region.village', 'Gegerkalong');

        // Atasan melihat bawahannya, tapi bukan data alamat.
        $this->actAs($this->atasanUser)->getJson("/api/employees/{$this->member->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.province_code')
            ->assertJsonMissingPath('data.village_code')
            ->assertJsonMissingPath('data.region');

        $list = $this->actAs($this->atasanUser)->getJson('/api/employees')->assertOk();

        $this->assertStringNotContainsString('Gegerkalong', $list->getContent());
        $this->assertStringNotContainsString('32.73.01.1002', $list->getContent());
    }

    public function test_region_validation_requires_a_consistent_chain(): void
    {
        $this->seedRegions();
        $url = "/api/employees/{$this->member->id}";

        $this->actAs($this->hr)->putJson($url, $this->payload($this->chain()))->assertOk();

        $bad = [
            'partial' => [['province_code' => '32'], 'regency_code'],
            'village from another chain' => [['village_code' => '35.77.01.1001'] + $this->chain(), 'village_code'],
            'regency from another province' => [['regency_code' => '35.77'] + $this->chain(), 'village_code'],
            'unknown province' => [['province_code' => '99'] + $this->chain(), 'province_code'],
        ];

        foreach ($bad as [$override, $field]) {
            $this->actAs($this->hr)->putJson($url, $this->payload($override))
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        $this->assertSame('32.73.01.1002', $this->member->fresh()->village_code);

        // Semua kosong = wilayah alamat dihapus.
        $this->actAs($this->hr)->putJson($url, $this->payload([
            'province_code' => null,
            'regency_code' => null,
            'district_code' => null,
            'village_code' => null,
        ]))->assertOk()->assertJsonPath('data.province_code', null);

        $this->assertNull($this->member->fresh()->village_code);
    }

    public function test_manager_options_list_only_valid_managers_and_is_hr_admin_only(): void
    {
        $this->makeEmployee('E8', $this->makeUser(Role::Atasan, 'citra@t.test'), null, 'Citra Atasan');
        $this->makeEmployee('E9', $this->makeUser(Role::Atasan, 'locked@t.test', false), null, 'Atasan Terkunci');
        $this->makeEmployee('E10', $this->makeUser(Role::Atasan, 'gone@t.test'), null, 'Atasan Nonaktif', false);
        $this->makeEmployee('E11', null, null, 'Tanpa Akun');

        $this->getJson('/api/employees/managers')->assertUnauthorized();

        $res = $this->actAs($this->hr)->getJson('/api/employees/managers')
            ->assertOk()
            ->assertJsonPath('data.0.employee_number', 'E1')
            ->assertJsonMissingPath('data.0.nik')
            ->assertJsonMissingPath('data.0.manager_id');

        $this->assertSame(['Budi Atasan', 'Citra Atasan'], collect($res->json('data'))->pluck('full_name')->all());

        $this->actAs($this->hr)->getJson('/api/employees/managers?search=Citra')->assertOk()->assertJsonCount(1, 'data');
        $this->actAs($this->hr)->getJson('/api/employees/managers?search=E8')->assertOk()->assertJsonCount(1, 'data');
        $this->actAs($this->superUser)->getJson('/api/employees/managers')->assertOk()->assertJsonCount(2, 'data');

        foreach ([$this->finance, $this->atasanUser, $this->karyawanUser] as $user) {
            $this->actAs($user)->getJson('/api/employees/managers')->assertForbidden();
        }
    }

    public function test_resource_includes_manager_summary(): void
    {
        $this->actAs($this->hr)->getJson("/api/employees/{$this->member->id}")
            ->assertOk()
            ->assertJsonPath('data.manager_id', $this->atasanEmp->id)
            ->assertJsonPath('data.manager.full_name', 'Budi Atasan');

        $this->actAs($this->hr)->getJson("/api/employees/{$this->atasanEmp->id}")
            ->assertOk()
            ->assertJsonPath('data.manager', null);
    }
}
