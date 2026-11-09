<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Payroll;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditLogReadTest extends TestCase
{
    use RefreshDatabase;

    private User $superUser;
    private User $hr;
    private User $finance;
    private User $atasanUser;
    private User $karyawanUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superUser = $this->makeUser(Role::SuperAdmin, 'super@t.test');
        $this->hr = $this->makeUser(Role::Hr, 'hr@t.test');
        $this->finance = $this->makeUser(Role::Finance, 'fin@t.test');
        $this->atasanUser = $this->makeUser(Role::Atasan, 'atasan@t.test');
        $this->karyawanUser = $this->makeUser(Role::Karyawan, 'kary@t.test');
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

    private function log(string $action, array $attributes = []): AuditLog
    {
        return AuditLog::create(array_merge(['action' => $action], $attributes));
    }

    public function test_only_super_admin_can_read_audit_logs(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/audit-logs')->assertUnauthorized();

        foreach ([$this->hr, $this->finance, $this->atasanUser, $this->karyawanUser] as $user) {
            $this->actAs($user)->getJson('/api/audit-logs')->assertForbidden();
            $this->actAs($user)->getJson('/api/audit-logs/actions')->assertForbidden();
        }

        $this->actAs($this->superUser)->getJson('/api/audit-logs')->assertOk();
        $this->actAs($this->superUser)->getJson('/api/audit-logs/actions')->assertOk();
    }

    public function test_logs_are_listed_newest_first_with_actor(): void
    {
        $this->log('test.first', ['user_id' => $this->hr->id, 'ip_address' => '10.0.0.1']);
        $this->log('test.second', ['user_id' => $this->finance->id]);

        $this->actAs($this->superUser)
            ->getJson('/api/audit-logs')
            ->assertOk()
            ->assertJsonPath('data.0.action', 'test.second')
            ->assertJsonPath('data.0.actor.email', 'fin@t.test')
            ->assertJsonPath('data.1.action', 'test.first')
            ->assertJsonPath('data.1.actor.email', 'hr@t.test')
            ->assertJsonPath('data.1.ip_address', '10.0.0.1');
    }

    public function test_entry_without_actor_has_null_actor(): void
    {
        $this->log('auth.login_failed', ['new_values' => ['email' => 'x@t.test']]);

        $this->actAs($this->superUser)
            ->getJson('/api/audit-logs?action=auth.login_failed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.actor', null);
    }

    public function test_filter_by_action(): void
    {
        $this->log('auth.login', ['user_id' => $this->hr->id]);
        $this->log('auth.logout', ['user_id' => $this->hr->id]);

        $this->actAs($this->superUser)
            ->getJson('/api/audit-logs?action=auth.logout')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'auth.logout');
    }

    public function test_filter_by_actor(): void
    {
        $this->log('auth.login', ['user_id' => $this->hr->id]);
        $this->log('auth.login', ['user_id' => $this->finance->id]);

        $this->actAs($this->superUser)
            ->getJson('/api/audit-logs?action=auth.login&user_id=' . $this->finance->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.actor.email', 'fin@t.test');
    }

    public function test_date_filter_is_inclusive_of_the_whole_day(): void
    {
        DB::table('audit_logs')->insert([
            ['action' => 'edge.before', 'created_at' => '2020-03-14 23:59:59'],
            ['action' => 'edge.inside', 'created_at' => '2020-03-15 08:00:00'],
            ['action' => 'edge.after', 'created_at' => '2020-03-16 00:00:00'],
        ]);

        $this->actAs($this->superUser)
            ->getJson('/api/audit-logs?from=2020-03-15&to=2020-03-15')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'edge.inside');
    }

    public function test_date_range_must_not_be_reversed(): void
    {
        $this->actAs($this->superUser)
            ->getJson('/api/audit-logs?from=2020-03-16&to=2020-03-15')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');
    }

    public function test_payroll_values_are_hidden_but_changed_fields_remain(): void
    {
        $this->log('payroll.updated', [
            'auditable_type' => (new Payroll)->getMorphClass(),
            'auditable_id' => 1,
            'old_values' => ['net_salary' => 1111111],
            'new_values' => ['net_salary' => 7654321],
        ]);

        $response = $this->actAs($this->superUser)
            ->getJson('/api/audit-logs?action=payroll.updated')
            ->assertOk()
            ->assertJsonPath('data.0.changed_fields', ['net_salary'])
            ->assertJsonPath('data.0.old_values', [])
            ->assertJsonPath('data.0.new_values', [])
            ->assertJsonPath('data.0.subject.type', 'Payroll');

        $this->assertStringNotContainsString('7654321', $response->getContent());
        $this->assertStringNotContainsString('1111111', $response->getContent());
    }

    public function test_values_of_allowed_models_are_shown_and_unlisted_columns_are_dropped(): void
    {
        $this->log('department.updated', [
            'auditable_type' => (new Department)->getMorphClass(),
            'auditable_id' => 1,
            'old_values' => ['name' => 'Keuangan', 'internal_note' => 'rahasia'],
            'new_values' => ['name' => 'Finance', 'internal_note' => 'rahasia baru'],
        ]);

        $response = $this->actAs($this->superUser)
            ->getJson('/api/audit-logs?action=department.updated')
            ->assertOk()
            ->assertJsonPath('data.0.old_values', ['name' => 'Keuangan'])
            ->assertJsonPath('data.0.new_values', ['name' => 'Finance']);

        $this->assertStringNotContainsString('rahasia', $response->getContent());
    }

    public function test_user_entries_never_expose_the_password(): void
    {
        $rows = $this->actAs($this->superUser)
            ->getJson('/api/audit-logs?action=user.created')
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $this->assertArrayNotHasKey('password', $row['new_values']);
            $this->assertArrayHasKey('email', $row['new_values']);
        }
    }

    public function test_entries_without_a_subject_show_their_values_as_recorded(): void
    {
        $this->log('report.exported', ['new_values' => ['type' => 'payroll', 'format' => 'csv']]);

        $this->actAs($this->superUser)
            ->getJson('/api/audit-logs?action=report.exported')
            ->assertOk()
            ->assertJsonPath('data.0.subject', null)
            ->assertJsonPath('data.0.new_values', ['type' => 'payroll', 'format' => 'csv']);
    }

    public function test_actions_endpoint_lists_each_action_once(): void
    {
        $this->log('zeta.event');
        $this->log('zeta.event');
        $this->log('alpha.event');

        $actions = $this->actAs($this->superUser)
            ->getJson('/api/audit-logs/actions')
            ->assertOk()
            ->json('data');

        $this->assertContains('alpha.event', $actions);
        $this->assertContains('zeta.event', $actions);
        $this->assertSame($actions, array_values(array_unique($actions)));
    }
}
