<?php

namespace Tests\Feature;

use App\Enums\EmploymentStatus;
use App\Enums\JobOpeningStatus;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobOpening;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationTest extends TestCase
{
    use RefreshDatabase;

    private User $hr;
    private User $superUser;
    private User $finance;
    private User $atasanUser;
    private User $karyawanUser;
    private Department $dept;
    private Department $emptyDept;
    private Department $jobDept;
    private Position $pos;
    private Position $emptyPos;
    private Position $jobPos;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hr = $this->makeUser(Role::Hr, 'hr@t.test');
        $this->superUser = $this->makeUser(Role::SuperAdmin, 'super@t.test');
        $this->finance = $this->makeUser(Role::Finance, 'fin@t.test');
        $this->atasanUser = $this->makeUser(Role::Atasan, 'atasan@t.test');
        $this->karyawanUser = $this->makeUser(Role::Karyawan, 'kary@t.test');

        $this->dept = Department::create(['name' => 'Operasional']);
        $this->emptyDept = Department::create(['name' => 'Kosong']);
        $this->jobDept = Department::create(['name' => 'Hanya Lowongan']);

        $this->pos = Position::create(['name' => 'Staff']);
        $this->emptyPos = Position::create(['name' => 'Cadangan']);
        $this->jobPos = Position::create(['name' => 'Rekrut']);

        Employee::forceCreate([
            'user_id' => null,
            'department_id' => $this->dept->id,
            'position_id' => $this->pos->id,
            'employee_number' => 'E1',
            'full_name' => 'Karyawan E1',
            'hire_date' => '2024-01-15',
            'employment_status' => EmploymentStatus::Tetap,
            'is_active' => true,
        ]);

        JobOpening::forceCreate([
            'title' => 'Lowongan Uji',
            'department_id' => $this->jobDept->id,
            'position_id' => $this->jobPos->id,
            'headcount' => 1,
            'status' => JobOpeningStatus::Open,
            'created_by' => $this->hr->id,
        ]);
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

    /** Guard Sanctum menyimpan user dari request sebelumnya, jadi di-reset tiap ganti user. */
    private function actAs(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user);
    }

    public function test_only_hr_and_super_admin_can_manage_organization(): void
    {
        $targets = ['departments' => $this->emptyDept, 'positions' => $this->emptyPos];

        // Tamu dicek dulu, sebelum ada actAs yang membuat user tetap login.
        foreach (array_keys($targets) as $path) {
            $this->getJson("/api/{$path}")->assertUnauthorized();
        }

        foreach ($targets as $path => $item) {
            foreach ([$this->finance, $this->atasanUser, $this->karyawanUser] as $user) {
                $this->actAs($user)->getJson("/api/{$path}")->assertForbidden();
                $this->actAs($user)->postJson("/api/{$path}", ['name' => 'Baru'])->assertForbidden();
                $this->actAs($user)->putJson("/api/{$path}/{$item->id}", ['name' => 'Ubah'])->assertForbidden();
                $this->actAs($user)->deleteJson("/api/{$path}/{$item->id}")->assertForbidden();
            }
        }

        $this->assertSame(3, Department::count());
        $this->assertSame(3, Position::count());
        $this->assertSame('Kosong', $this->emptyDept->fresh()->name);
        $this->assertSame('Cadangan', $this->emptyPos->fresh()->name);

        $this->actAs($this->superUser)->getJson('/api/departments')->assertOk();
        $this->actAs($this->hr)->getJson('/api/positions')->assertOk();
    }

    public function test_create_trims_name_and_is_audited(): void
    {
        $targets = [
            ['departments', 'department'],
            ['positions', 'position'],
        ];

        foreach ($targets as [$path, $prefix]) {
            $this->actAs($this->hr)->postJson("/api/{$path}", ['name' => '  Keuangan  '])
                ->assertCreated()
                ->assertJsonPath('data.name', 'Keuangan')
                ->assertJsonPath('data.employees_count', 0);

            $this->actAs($this->superUser)->postJson("/api/{$path}", ['name' => 'Pemasaran'])->assertCreated();

            $log = AuditLog::where('action', "{$prefix}.created")
                ->where('user_id', $this->hr->id)
                ->firstOrFail();

            $this->assertSame('Keuangan', $log->new_values['name']);

            // Data awal dari setUp() juga tercatat tanpa pelaku, jadi hitung hanya yang lewat API.
            $this->assertSame(
                2,
                AuditLog::where('action', "{$prefix}.created")
                    ->whereIn('user_id', [$this->hr->id, $this->superUser->id])
                    ->count()
            );
        }
    }

    public function test_name_validation_and_uniqueness(): void
    {
        $before = Department::count();

        $bad = [
            [[], 'name'],
            [['name' => ''], 'name'],
            [['name' => '   '], 'name'],
            [['name' => str_repeat('a', 101)], 'name'],
            [['name' => ['x']], 'name'],
            [['name' => ' Operasional '], 'name'],
        ];

        foreach ($bad as [$payload, $field]) {
            $this->actAs($this->hr)->postJson('/api/departments', $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        $this->assertSame($before, Department::count());

        // Mengganti nama menjadi nama milik yang lain ditolak.
        $this->actAs($this->hr)->putJson("/api/departments/{$this->emptyDept->id}", ['name' => 'Operasional'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->assertSame('Kosong', $this->emptyDept->fresh()->name);

        // Menyimpan dengan nama sendiri tidak dianggap bentrok.
        $this->actAs($this->hr)->putJson("/api/departments/{$this->emptyDept->id}", ['name' => 'Kosong'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Kosong');
    }

    public function test_rename_is_audited_with_old_and_new_values(): void
    {
        $this->actAs($this->hr)->putJson("/api/positions/{$this->emptyPos->id}", ['name' => 'Koordinator'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Koordinator');

        $log = AuditLog::where('action', 'position.updated')->where('auditable_id', $this->emptyPos->id)->firstOrFail();

        $this->assertSame($this->hr->id, $log->user_id);
        $this->assertSame('Cadangan', $log->old_values['name']);
        $this->assertSame('Koordinator', $log->new_values['name']);
    }

    public function test_delete_only_when_unused(): void
    {
        // Dipakai karyawan.
        $this->actAs($this->hr)->deleteJson("/api/departments/{$this->dept->id}")->assertStatus(409);
        $this->actAs($this->hr)->deleteJson("/api/positions/{$this->pos->id}")->assertStatus(409);

        // Dipakai lowongan.
        $this->actAs($this->hr)->deleteJson("/api/departments/{$this->jobDept->id}")->assertStatus(409);
        $this->actAs($this->hr)->deleteJson("/api/positions/{$this->jobPos->id}")->assertStatus(409);

        $this->assertSame(3, Department::count());
        $this->assertSame(3, Position::count());

        // Tidak dipakai siapa pun.
        $this->actAs($this->hr)->deleteJson("/api/departments/{$this->emptyDept->id}")->assertNoContent();
        $this->actAs($this->superUser)->deleteJson("/api/positions/{$this->emptyPos->id}")->assertNoContent();

        $this->assertSame(2, Department::count());
        $this->assertSame(2, Position::count());
        $this->assertTrue(AuditLog::where('action', 'department.deleted')->where('user_id', $this->hr->id)->exists());
        $this->assertTrue(AuditLog::where('action', 'position.deleted')->where('user_id', $this->superUser->id)->exists());
    }

    public function test_list_search_and_employee_counts(): void
    {
        $res = $this->actAs($this->hr)->getJson('/api/departments')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->assertSame(['Hanya Lowongan', 'Kosong', 'Operasional'], collect($res->json('data'))->pluck('name')->all());

        $byName = collect($res->json('data'))->keyBy('name');
        $this->assertSame(1, $byName['Operasional']['employees_count']);
        $this->assertSame(0, $byName['Kosong']['employees_count']);

        $this->actAs($this->hr)->getJson('/api/departments?search=Opera')->assertOk()->assertJsonCount(1, 'data');
        $this->actAs($this->hr)->getJson('/api/positions?search=tidak-ada')->assertOk()->assertJsonCount(0, 'data');
        $this->actAs($this->hr)->getJson('/api/positions')->assertOk()->assertJsonCount(3, 'data');
    }
}
