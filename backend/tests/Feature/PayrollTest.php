<?php

namespace Tests\Feature;

use App\Enums\EmploymentStatus;
use App\Enums\PayrollStatus;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollItem;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use LogicException;
use Tests\TestCase;

class PayrollTest extends TestCase
{
    use RefreshDatabase;

    private Department $dept;
    private Position $pos;
    private User $hr;
    private User $finance;
    private User $superUser;
    private User $atasanUser;
    private User $karyawanUser;
    private User $karyawan2User;
    private Employee $hrEmp;
    private Employee $finEmp;
    private Employee $atasanEmp;
    private Employee $member; // bawahan atasan
    private Employee $member2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2030-02-15 09:00:00'));

        $this->dept = Department::create(['name' => 'Operasional']);
        $this->pos = Position::create(['name' => 'Staff']);

        $this->hr = $this->makeUser(Role::Hr, 'hr@t.test');
        $this->finance = $this->makeUser(Role::Finance, 'fin@t.test');
        $this->superUser = $this->makeUser(Role::SuperAdmin, 'super@t.test');
        $this->atasanUser = $this->makeUser(Role::Atasan, 'atasan@t.test');
        $this->karyawanUser = $this->makeUser(Role::Karyawan, 'kary@t.test');
        $this->karyawan2User = $this->makeUser(Role::Karyawan, 'kary2@t.test');

        $this->hrEmp = $this->makeEmployee('H1', $this->hr);
        $this->finEmp = $this->makeEmployee('F1', $this->finance);
        $this->atasanEmp = $this->makeEmployee('E1', $this->atasanUser);
        $this->member = $this->makeEmployee('E2', $this->karyawanUser, $this->atasanEmp);
        $this->member2 = $this->makeEmployee('E3', $this->karyawan2User);
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

    private function makeEmployee(string $number, ?User $user = null, ?Employee $manager = null): Employee
    {
        return Employee::forceCreate([
            'user_id' => $user?->id,
            'manager_id' => $manager?->id,
            'department_id' => $this->dept->id,
            'position_id' => $this->pos->id,
            'employee_number' => $number,
            'full_name' => "Karyawan {$number}",
            'hire_date' => '2024-01-15',
            'employment_status' => EmploymentStatus::Tetap,
            'is_active' => true,
        ]);
    }

    private function defaultItems(): array
    {
        return [
            ['type' => 'basic_salary', 'label' => null, 'amount' => 10_000_000],
            ['type' => 'allowance', 'label' => 'Tunjangan transport', 'amount' => 1_000_000],
            ['type' => 'bpjs', 'label' => 'BPJS Kesehatan', 'amount' => 400_000],
            ['type' => 'pph21', 'label' => null, 'amount' => 600_000],
        ];
    }

    /** bruto 11.000.000, potongan 1.000.000, neto 10.000.000 (kalau pakai item bawaan). */
    private function makePayroll(Employee $employee, int $month = 1, bool $final = false, ?array $items = null): Payroll
    {
        $payroll = new Payroll();
        $payroll->forceFill([
            'employee_id' => $employee->id,
            'year' => 2030,
            'month' => $month,
            'revision_no' => 0,
            'status' => PayrollStatus::Draft,
            'prepared_by' => $this->hr->id,
        ])->save();

        foreach ($items ?? $this->defaultItems() as $item) {
            $payroll->items()->create($item);
        }

        if ($final) {
            // Query builder: sengaja melewati guard model, khusus untuk menyiapkan data uji.
            Payroll::whereKey($payroll->id)->update([
                'status' => 'finalized',
                'finalized_by' => $this->finance->id,
                'finalized_at' => now(),
            ]);
        }

        return $payroll->fresh();
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'employee_id' => $this->member->id,
            'year' => 2030,
            'month' => 1,
            'items' => $this->defaultItems(),
        ], $override);
    }

    /** Guard Sanctum menyimpan user dari request sebelumnya, jadi di-reset tiap ganti user. */
    private function actAs(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user);
    }

    private function ids($response): array
    {
        return collect($response->json('data'))->pluck('id')->all();
    }

    public function test_hr_creates_draft_payroll_with_computed_totals(): void
    {
        $this->actAs($this->hr)->postJson('/api/payrolls', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.revision_no', 0)
            ->assertJsonPath('data.totals.gross', 11_000_000)
            ->assertJsonPath('data.totals.deductions', 1_000_000)
            ->assertJsonPath('data.totals.net', 10_000_000)
            ->assertJsonCount(4, 'data.items');

        $payroll = Payroll::firstOrFail();

        $this->assertSame($this->hr->id, $payroll->prepared_by);
        $this->assertTrue(AuditLog::where('action', 'payroll.created')->where('user_id', $this->hr->id)->exists());
        $this->assertTrue(AuditLog::where('action', 'payroll.items_replaced')->where('auditable_id', $payroll->id)->exists());
    }

    public function test_only_hr_can_create_payroll(): void
    {
        foreach ([$this->finance, $this->atasanUser, $this->karyawanUser, $this->superUser] as $user) {
            $this->actAs($user)->postJson('/api/payrolls', $this->payload())->assertForbidden();
        }

        $this->assertSame(0, Payroll::count());
    }

    public function test_duplicate_period_for_same_employee_is_rejected(): void
    {
        $this->actAs($this->hr)->postJson('/api/payrolls', $this->payload())->assertCreated();

        $this->actAs($this->hr)->postJson('/api/payrolls', $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('employee_id');

        $this->assertSame(1, Payroll::count());
    }

    public function test_future_period_is_rejected(): void
    {
        $this->actAs($this->hr)->postJson('/api/payrolls', $this->payload(['month' => 2]))->assertCreated();

        $this->actAs($this->hr)
            ->postJson('/api/payrolls', $this->payload(['employee_id' => $this->member2->id, 'month' => 3]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('month');
    }

    public function test_hr_cannot_prepare_own_payroll(): void
    {
        $this->actAs($this->hr)
            ->postJson('/api/payrolls', $this->payload(['employee_id' => $this->hrEmp->id]))
            ->assertForbidden();

        $this->assertSame(0, Payroll::count());
    }

    public function test_item_validation_and_atomic_create(): void
    {
        $bad = [
            'negative amount' => [['type' => 'bonus', 'label' => 'x', 'amount' => -1]],
            'fractional amount' => [['type' => 'bonus', 'label' => 'x', 'amount' => 1500000.5]],
            'two basic salaries' => [
                ['type' => 'basic_salary', 'amount' => 1],
                ['type' => 'basic_salary', 'amount' => 2],
            ],
            'unknown type' => [['type' => 'hadiah', 'amount' => 1]],
        ];

        foreach ($bad as $items) {
            $this->actAs($this->hr)
                ->postJson('/api/payrolls', $this->payload(['items' => $items]))
                ->assertUnprocessable();
        }

        // Gagal di tengah jalan = payroll-nya ikut batal, tidak ada draft yatim.
        $this->assertSame(0, Payroll::count());
        $this->assertSame(0, PayrollItem::count());
    }

    public function test_hr_replaces_items_on_draft_and_change_is_audited(): void
    {
        $payroll = $this->makePayroll($this->member);

        $this->actAs($this->hr)->putJson("/api/payrolls/{$payroll->id}/items", ['items' => [
            ['type' => 'basic_salary', 'amount' => 12_000_000],
            ['type' => 'bonus', 'label' => 'Bonus kinerja', 'amount' => 2_000_000],
        ]])
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.totals.net', 14_000_000);

        $log = AuditLog::where('action', 'payroll.items_replaced')
            ->where('auditable_id', $payroll->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($this->hr->id, $log->user_id);
        $this->assertCount(4, $log->old_values['items']);
        $this->assertCount(2, $log->new_values['items']);
    }

    public function test_finance_can_only_edit_bpjs_and_pph21(): void
    {
        $payroll = $this->makePayroll($this->member);

        $this->actAs($this->finance)->putJson("/api/payrolls/{$payroll->id}/items", ['items' => [
            ['type' => 'bpjs', 'label' => 'BPJS', 'amount' => 500_000],
            ['type' => 'pph21', 'amount' => 700_000],
        ]])
            ->assertOk()
            ->assertJsonCount(4, 'data.items')
            ->assertJsonPath('data.totals.gross', 11_000_000)
            ->assertJsonPath('data.totals.deductions', 1_200_000)
            ->assertJsonPath('data.totals.net', 9_800_000);

        $this->actAs($this->finance)->putJson("/api/payrolls/{$payroll->id}/items", ['items' => [
            ['type' => 'basic_salary', 'amount' => 99_000_000],
        ]])->assertUnprocessable();

        $this->assertSame(
            10_000_000,
            PayrollItem::where('payroll_id', $payroll->id)->where('type', 'basic_salary')->value('amount')
        );
    }

    public function test_outsiders_and_owners_cannot_edit_items(): void
    {
        $payroll = $this->makePayroll($this->member);

        foreach ([$this->atasanUser, $this->karyawanUser, $this->superUser] as $user) {
            $this->actAs($user)->putJson("/api/payrolls/{$payroll->id}/items", ['items' => []])->assertForbidden();
        }

        $hrOwn = $this->makePayroll($this->hrEmp);
        $this->actAs($this->hr)->putJson("/api/payrolls/{$hrOwn->id}/items", ['items' => []])->assertForbidden();

        $finOwn = $this->makePayroll($this->finEmp);
        $this->actAs($this->finance)->putJson("/api/payrolls/{$finOwn->id}/items", ['items' => []])->assertForbidden();
    }

    public function test_finance_finalizes_and_actor_and_time_are_recorded(): void
    {
        $payroll = $this->makePayroll($this->member);

        $this->actAs($this->finance)->postJson("/api/payrolls/{$payroll->id}/finalize")
            ->assertOk()
            ->assertJsonPath('data.status', 'finalized')
            ->assertJsonPath('data.finalization.by.id', $this->finance->id);

        $fresh = $payroll->fresh();

        $this->assertSame($this->finance->id, $fresh->finalized_by);
        $this->assertSame('2030-02-15', $fresh->finalized_at->toDateString());

        $log = AuditLog::where('action', 'payroll.updated')->where('auditable_id', $payroll->id)->firstOrFail();

        $this->assertSame($this->finance->id, $log->user_id);
        $this->assertSame('draft', $log->old_values['status']);
        $this->assertSame('finalized', $log->new_values['status']);
    }

    public function test_only_finance_can_finalize_and_never_own_payroll(): void
    {
        $payroll = $this->makePayroll($this->member);

        foreach ([$this->hr, $this->atasanUser, $this->karyawanUser, $this->superUser] as $user) {
            $this->actAs($user)->postJson("/api/payrolls/{$payroll->id}/finalize")->assertForbidden();
        }

        $this->assertSame(PayrollStatus::Draft, $payroll->fresh()->status);

        $own = $this->makePayroll($this->finEmp);
        $this->actAs($this->finance)->postJson("/api/payrolls/{$own->id}/finalize")->assertForbidden();
        $this->assertSame(PayrollStatus::Draft, $own->fresh()->status);
    }

    public function test_finalize_requires_one_basic_salary_and_non_negative_net(): void
    {
        $noBasic = $this->makePayroll($this->member, items: [
            ['type' => 'allowance', 'label' => 'x', 'amount' => 1_000_000],
        ]);

        $negativeNet = $this->makePayroll($this->member2, items: [
            ['type' => 'basic_salary', 'amount' => 1_000_000],
            ['type' => 'pph21', 'amount' => 5_000_000],
        ]);

        foreach ([$noBasic, $negativeNet] as $payroll) {
            $this->actAs($this->finance)->postJson("/api/payrolls/{$payroll->id}/finalize")
                ->assertUnprocessable()
                ->assertJsonValidationErrors('items');

            $this->assertSame(PayrollStatus::Draft, $payroll->fresh()->status);
        }
    }

    public function test_finalized_payroll_is_locked_through_the_api(): void
    {
        $payroll = $this->makePayroll($this->member, final: true);

        foreach ([$this->hr, $this->finance] as $user) {
            $this->actAs($user)->putJson("/api/payrolls/{$payroll->id}/items", ['items' => []])->assertStatus(409);
        }

        $this->actAs($this->hr)->deleteJson("/api/payrolls/{$payroll->id}")->assertStatus(409);

        $finalizedBy = $payroll->fresh()->finalized_by;
        $this->actAs($this->finance)->postJson("/api/payrolls/{$payroll->id}/finalize")->assertStatus(409);

        $this->assertSame($finalizedBy, $payroll->fresh()->finalized_by);
        $this->assertSame(4, PayrollItem::where('payroll_id', $payroll->id)->count());
    }

    public function test_model_guards_block_silent_changes_on_final_payroll(): void
    {
        $payroll = $this->makePayroll($this->member, final: true);
        $item = $payroll->items()->firstOrFail();

        $attempts = [
            fn () => $item->forceFill(['amount' => 1])->save(),
            fn () => $item->delete(),
            fn () => PayrollItem::forceCreate(['payroll_id' => $payroll->id, 'type' => 'bonus', 'amount' => 1]),
            fn () => $payroll->forceFill(['note' => 'diam-diam'])->save(),
            fn () => $payroll->delete(),
        ];

        foreach ($attempts as $attempt) {
            try {
                $attempt();
                $this->fail('Perubahan pada payroll final seharusnya ditolak.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(4, $payroll->items()->count());
        $this->assertNull($payroll->fresh()->note);
    }

    public function test_hr_can_delete_draft_but_not_others(): void
    {
        $payroll = $this->makePayroll($this->member);

        $this->actAs($this->finance)->deleteJson("/api/payrolls/{$payroll->id}")->assertForbidden();
        $this->actAs($this->hr)->deleteJson("/api/payrolls/{$payroll->id}")->assertNoContent();

        $this->assertSame(0, Payroll::count());
        $this->assertSame(0, PayrollItem::count());
        $this->assertTrue(AuditLog::where('action', 'payroll.deleted')->where('user_id', $this->hr->id)->exists());
    }

    public function test_adjustment_flow_keeps_original_untouched(): void
    {
        $original = $this->makePayroll($this->member, final: true);

        $valid = [
            'note' => 'Bonus bulan lalu salah input',
            'items' => [['type' => 'bonus', 'label' => 'Koreksi bonus', 'amount' => -500_000]],
        ];

        $adjId = $this->actAs($this->hr)->postJson("/api/payrolls/{$original->id}/adjustments", $valid)
            ->assertCreated()
            ->assertJsonPath('data.revision_no', 1)
            ->assertJsonPath('data.adjusts_payroll_id', $original->id)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.totals.gross', -500_000)
            ->assertJsonPath('data.totals.net', -500_000)
            ->json('data.id');

        // Yang asli tidak tersentuh.
        $fresh = $original->fresh();
        $this->assertSame(PayrollStatus::Finalized, $fresh->status);
        $this->assertSame(4, $fresh->items()->count());

        // Selama koreksi pertama masih draft, koreksi kedua ditolak.
        $this->actAs($this->hr)->postJson("/api/payrolls/{$original->id}/adjustments", $valid)
            ->assertUnprocessable();

        $this->actAs($this->finance)->postJson("/api/payrolls/{$adjId}/finalize")
            ->assertOk()
            ->assertJsonPath('data.status', 'finalized');

        // Koreksi tidak bisa dikoreksi lagi; koreksi dibuat dari yang asli.
        $this->actAs($this->hr)->postJson("/api/payrolls/{$adjId}/adjustments", $valid)->assertStatus(409);

        $this->actAs($this->hr)->postJson("/api/payrolls/{$original->id}/adjustments", $valid)
            ->assertCreated()
            ->assertJsonPath('data.revision_no', 2);
    }

    public function test_adjustment_guards(): void
    {
        $valid = [
            'note' => 'Koreksi',
            'items' => [['type' => 'bonus', 'label' => 'k', 'amount' => -1000]],
        ];

        // Payroll asli yang masih draft belum bisa dikoreksi.
        $draft = $this->makePayroll($this->member);
        $this->actAs($this->hr)->postJson("/api/payrolls/{$draft->id}/adjustments", $valid)->assertStatus(409);

        $final = $this->makePayroll($this->member, 2, final: true);
        $url = "/api/payrolls/{$final->id}/adjustments";

        $this->actAs($this->hr)->postJson($url, ['items' => $valid['items']])
            ->assertUnprocessable()->assertJsonValidationErrors('note');

        $this->actAs($this->hr)->postJson($url, ['note' => 'Koreksi', 'items' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->actAs($this->hr)
            ->postJson($url, ['note' => 'Koreksi', 'items' => [['type' => 'bonus', 'amount' => 0]]])
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.amount');

        foreach ([$this->finance, $this->atasanUser, $this->karyawanUser, $this->superUser] as $user) {
            $this->actAs($user)->postJson($url, $valid)->assertForbidden();
        }

        $own = $this->makePayroll($this->hrEmp, 1, final: true);
        $this->actAs($this->hr)->postJson("/api/payrolls/{$own->id}/adjustments", $valid)->assertForbidden();

        $this->assertSame(0, Payroll::where('revision_no', '>', 0)->count());
    }

    public function test_payslip_visibility(): void
    {
        $memberFinal = $this->makePayroll($this->member, 1, final: true);
        $memberDraft = $this->makePayroll($this->member, 2);
        $member2Final = $this->makePayroll($this->member2, 1, final: true);
        $atasanFinal = $this->makePayroll($this->atasanEmp, 1, final: true);

        $this->getJson('/api/payrolls')->assertUnauthorized();

        // Karyawan: hanya slip sendiri yang sudah final.
        $res = $this->actAs($this->karyawanUser)->getJson('/api/payrolls')->assertOk();
        $this->assertSame([$memberFinal->id], $this->ids($res));

        $own = $this->actAs($this->karyawanUser)->getJson("/api/payrolls/{$memberFinal->id}")->assertOk();
        $this->assertStringContainsString('private', (string) $own->headers->get('Cache-Control'));

        $this->actAs($this->karyawanUser)->getJson("/api/payrolls/{$memberDraft->id}")->assertForbidden();
        $this->actAs($this->karyawanUser)->getJson("/api/payrolls/{$member2Final->id}")->assertForbidden();

        // Atasan: slip sendiri saja, bukan gaji timnya.
        $res = $this->actAs($this->atasanUser)->getJson('/api/payrolls')->assertOk();
        $this->assertSame([$atasanFinal->id], $this->ids($res));

        $this->actAs($this->atasanUser)->getJson("/api/payrolls/{$memberFinal->id}")->assertForbidden();
        $this->actAs($this->atasanUser)->getJson("/api/payrolls/{$atasanFinal->id}")->assertOk();

        // Super Admin tidak punya akses payroll.
        $this->actAs($this->superUser)->getJson('/api/payrolls')->assertForbidden();
        $this->actAs($this->superUser)->getJson("/api/payrolls/{$memberFinal->id}")->assertForbidden();

        // HR dan Finance melihat semuanya, termasuk draft.
        $this->actAs($this->hr)->getJson('/api/payrolls')->assertOk()->assertJsonCount(4, 'data');
        $this->actAs($this->finance)->getJson('/api/payrolls')->assertOk()->assertJsonCount(4, 'data');
    }

    public function test_list_filters_and_totals_without_loading_items(): void
    {
        $this->makePayroll($this->member, 1, final: true);
        $this->makePayroll($this->member, 2);
        $this->makePayroll($this->member2, 1, final: true);

        $this->actAs($this->hr)->getJson('/api/payrolls?year=2030&month=1')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.totals.net', 10_000_000)
            ->assertJsonMissingPath('data.0.items');

        $this->actAs($this->hr)->getJson('/api/payrolls?status=draft')->assertOk()->assertJsonCount(1, 'data');
        $this->actAs($this->hr)->getJson("/api/payrolls?employee_id={$this->member->id}")->assertOk()->assertJsonCount(2, 'data');
    }
}
