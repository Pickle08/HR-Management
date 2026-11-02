<?php

namespace Tests\Feature;

use App\Enums\CandidateStage;
use App\Enums\EmploymentStatus;
use App\Enums\JobOpeningStatus;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidateStageHistory;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobOpening;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class RecruitmentTest extends TestCase
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
    private Employee $karyawanEmp;
    private JobOpening $opening;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2030-02-15 09:00:00'));

        $this->dept = Department::create(['name' => 'Operasional']);
        $this->pos = Position::create(['name' => 'Staff']);

        $this->hr = $this->makeUser(Role::Hr, 'hr@t.test');
        $this->superUser = $this->makeUser(Role::SuperAdmin, 'super@t.test');
        $this->finance = $this->makeUser(Role::Finance, 'fin@t.test');
        $this->atasanUser = $this->makeUser(Role::Atasan, 'atasan@t.test');
        $this->karyawanUser = $this->makeUser(Role::Karyawan, 'kary@t.test');

        $this->atasanEmp = $this->makeEmployee('E1', $this->atasanUser);
        $this->karyawanEmp = $this->makeEmployee('E2', $this->karyawanUser);

        $this->opening = $this->makeOpening();
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

    private function makeEmployee(string $number, ?User $user = null): Employee
    {
        return Employee::forceCreate([
            'user_id' => $user?->id,
            'department_id' => $this->dept->id,
            'position_id' => $this->pos->id,
            'employee_number' => $number,
            'full_name' => "Karyawan {$number}",
            'hire_date' => '2024-01-15',
            'employment_status' => EmploymentStatus::Tetap,
            'is_active' => true,
        ]);
    }

    private function makeOpening(int $headcount = 2, string $title = 'Staff Operasional'): JobOpening
    {
        return JobOpening::forceCreate([
            'title' => $title,
            'department_id' => $this->dept->id,
            'position_id' => $this->pos->id,
            'headcount' => $headcount,
            'status' => JobOpeningStatus::Open,
            'created_by' => $this->hr->id,
        ]);
    }

    private function makeCandidate(
        CandidateStage $stage = CandidateStage::Applied,
        ?JobOpening $opening = null,
        string $email = 'calon@example.com',
    ): Candidate {
        return Candidate::forceCreate([
            'job_opening_id' => ($opening ?? $this->opening)->id,
            'full_name' => 'Calon Satu',
            'email' => $email,
            'phone' => '0811111111',
            'stage' => $stage,
        ]);
    }

    private function openingPayload(array $override = []): array
    {
        return array_merge([
            'title' => 'Staff Finance',
            'department_id' => $this->dept->id,
            'position_id' => $this->pos->id,
            'headcount' => 1,
            'description' => 'Mengelola kas',
        ], $override);
    }

    private function candidatePayload(array $override = []): array
    {
        return array_merge([
            'job_opening_id' => $this->opening->id,
            'full_name' => 'Calon Satu',
            'email' => 'calon@example.com',
            'phone' => '0811111111',
        ], $override);
    }

    private function convertPayload(array $override = []): array
    {
        return array_merge([
            'employee_number' => 'EMP-900',
            'hire_date' => '2030-03-01',
            'employment_status' => 'kontrak',
        ], $override);
    }

    /** Guard Sanctum menyimpan user dari request sebelumnya, jadi di-reset tiap ganti user. */
    private function actAs(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user);
    }

    private function move(Candidate|int $candidate, string $to, ?string $note = null)
    {
        $id = $candidate instanceof Candidate ? $candidate->id : $candidate;

        return $this->actAs($this->hr)->postJson("/api/candidates/{$id}/move", array_filter([
            'to_stage' => $to,
            'note' => $note,
        ]));
    }

    public function test_hr_and_super_admin_can_create_opening_and_others_cannot(): void
    {
        foreach ([$this->finance, $this->atasanUser, $this->karyawanUser] as $user) {
            $this->actAs($user)->postJson('/api/job-openings', $this->openingPayload())->assertForbidden();
        }

        $this->actAs($this->hr)->postJson('/api/job-openings', $this->openingPayload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.headcount', 1)
            ->assertJsonPath('data.hired_count', 0);

        $this->actAs($this->superUser)->postJson('/api/job-openings', $this->openingPayload())->assertCreated();

        $this->assertSame(3, JobOpening::count());
        $this->assertSame(
            $this->hr->id,
            JobOpening::where('title', 'Staff Finance')->orderBy('id')->firstOrFail()->created_by
        );
    }

    public function test_opening_validation(): void
    {
        $bad = [
            ['headcount' => 0],
            ['headcount' => 101],
            ['title' => ''],
            ['department_id' => 99999],
            ['position_id' => 99999],
        ];

        foreach ($bad as $override) {
            $this->actAs($this->hr)
                ->postJson('/api/job-openings', $this->openingPayload($override))
                ->assertUnprocessable();
        }

        $this->assertSame(1, JobOpening::count());
    }

    public function test_closing_opening_is_final_and_blocks_new_candidates(): void
    {
        $this->actAs($this->hr)->postJson("/api/job-openings/{$this->opening->id}/close")
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');

        $this->assertNotNull($this->opening->fresh()->closed_at);
        $this->assertTrue(
            AuditLog::where('action', 'jobopening.updated')->where('auditable_id', $this->opening->id)->exists()
        );

        $this->actAs($this->hr)->postJson("/api/job-openings/{$this->opening->id}/close")->assertStatus(409);

        $this->actAs($this->hr)->postJson('/api/candidates', $this->candidatePayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('job_opening_id');

        $this->assertSame(0, Candidate::count());
    }

    public function test_candidate_starts_in_applied_with_first_history_row_and_encrypted_phone(): void
    {
        $res = $this->actAs($this->hr)
            ->postJson('/api/candidates', $this->candidatePayload(['email' => 'Budi.Santoso@Example.COM']))
            ->assertCreated()
            ->assertJsonPath('data.stage', 'applied')
            ->assertJsonPath('data.email', 'budi.santoso@example.com')
            ->assertJsonPath('data.phone', '0811111111')
            ->assertJsonCount(1, 'data.history')
            ->assertJsonPath('data.history.0.from_stage', null)
            ->assertJsonPath('data.history.0.to_stage', 'applied')
            ->assertJsonPath('data.history.0.by.id', $this->hr->id);

        $raw = DB::table('candidates')->where('id', $res->json('data.id'))->value('phone');

        $this->assertNotNull($raw);
        $this->assertNotSame('0811111111', $raw);
    }

    public function test_duplicate_email_is_rejected_per_opening_only(): void
    {
        $this->actAs($this->hr)->postJson('/api/candidates', $this->candidatePayload())->assertCreated();

        $this->actAs($this->hr)
            ->postJson('/api/candidates', $this->candidatePayload(['email' => 'CALON@example.com']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $other = $this->makeOpening(1, 'Lowongan Lain');

        $this->actAs($this->hr)
            ->postJson('/api/candidates', $this->candidatePayload(['job_opening_id' => $other->id]))
            ->assertCreated();

        $this->assertSame(2, Candidate::count());
    }

    public function test_full_pipeline_records_every_move_in_history(): void
    {
        $id = $this->actAs($this->hr)->postJson('/api/candidates', $this->candidatePayload())
            ->assertCreated()
            ->json('data.id');

        foreach (['screening', 'interview', 'offer', 'accepted'] as $stage) {
            $this->move($id, $stage)->assertOk()->assertJsonPath('data.stage', $stage);
        }

        $history = CandidateStageHistory::where('candidate_id', $id)->orderBy('id')->get();

        $this->assertSame([null, 'applied', 'screening', 'interview', 'offer'], $history->pluck('from_stage')->all());
        $this->assertSame(['applied', 'screening', 'interview', 'offer', 'accepted'], $history->pluck('to_stage')->all());
        $this->assertSame([$this->hr->id], $history->pluck('changed_by')->unique()->values()->all());

        $this->assertSame(
            4,
            AuditLog::where('action', 'candidate.updated')->where('auditable_id', $id)->count()
        );
    }

    public function test_stage_transitions_cannot_skip_or_leave_final_stages(): void
    {
        $applied = $this->makeCandidate(CandidateStage::Applied, null, 'a@example.com');

        foreach (['offer', 'accepted', 'interview', 'applied'] as $target) {
            $this->move($applied, $target)->assertStatus(409);
        }

        $this->assertSame(CandidateStage::Applied, $applied->fresh()->stage);

        $rejected = $this->makeCandidate(CandidateStage::Rejected, null, 'b@example.com');
        $this->move($rejected, 'screening', 'buka lagi')->assertStatus(409);

        $accepted = $this->makeCandidate(CandidateStage::Accepted, null, 'c@example.com');
        $this->move($accepted, 'rejected', 'berubah pikiran')->assertStatus(409);

        $this->assertSame(CandidateStage::Rejected, $rejected->fresh()->stage);
        $this->assertSame(CandidateStage::Accepted, $accepted->fresh()->stage);
        $this->assertSame(0, CandidateStageHistory::count());
    }

    public function test_rejection_requires_a_note(): void
    {
        $candidate = $this->makeCandidate(CandidateStage::Interview);

        $this->move($candidate, 'rejected')->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->assertSame(CandidateStage::Interview, $candidate->fresh()->stage);

        $this->move($candidate, 'rejected', 'Kurang sesuai kebutuhan')
            ->assertOk()
            ->assertJsonPath('data.stage', 'rejected');

        $this->assertSame('Kurang sesuai kebutuhan', CandidateStageHistory::firstOrFail()->note);
    }

    public function test_stage_history_is_append_only(): void
    {
        $candidate = $this->makeCandidate();
        $history = CandidateStageHistory::forceCreate([
            'candidate_id' => $candidate->id,
            'from_stage' => null,
            'to_stage' => 'applied',
        ]);

        $attempts = [
            fn () => $history->forceFill(['note' => 'diubah'])->save(),
            fn () => $history->delete(),
        ];

        foreach ($attempts as $attempt) {
            try {
                $attempt();
                $this->fail('Riwayat tahap seharusnya tidak bisa diubah atau dihapus.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(1, CandidateStageHistory::count());
        $this->assertNull($history->fresh()->note);
    }

    public function test_only_hr_and_super_admin_can_touch_recruitment(): void
    {
        $candidate = $this->makeCandidate();

        $requests = [
            ['GET', '/api/job-openings', []],
            ['GET', "/api/job-openings/{$this->opening->id}", []],
            ['POST', '/api/job-openings', []],
            ['PUT', "/api/job-openings/{$this->opening->id}", []],
            ['POST', "/api/job-openings/{$this->opening->id}/close", []],
            ['GET', '/api/candidates', []],
            ['GET', "/api/candidates/{$candidate->id}", []],
            ['POST', '/api/candidates', []],
            ['PUT', "/api/candidates/{$candidate->id}", []],
            ['POST', "/api/candidates/{$candidate->id}/move", ['to_stage' => 'screening']],
            ['POST', "/api/candidates/{$candidate->id}/convert", []],
        ];

        $this->getJson('/api/candidates')->assertUnauthorized();

        foreach ([$this->finance, $this->atasanUser, $this->karyawanUser] as $user) {
            foreach ($requests as [$method, $url, $body]) {
                $this->actAs($user)->json($method, $url, $body)->assertForbidden();
            }
        }

        $this->assertSame(CandidateStage::Applied, $candidate->fresh()->stage);
        $this->assertSame(JobOpeningStatus::Open, $this->opening->fresh()->status);

        $this->actAs($this->superUser)->getJson('/api/candidates')->assertOk();
    }

    public function test_accepted_candidate_converts_to_employee(): void
    {
        $candidate = $this->makeCandidate(CandidateStage::Accepted);

        $this->actAs($this->hr)
            ->postJson("/api/candidates/{$candidate->id}/convert", $this->convertPayload())
            ->assertOk()
            ->assertJsonPath('data.stage', 'accepted');

        $employee = Employee::where('employee_number', 'EMP-900')->firstOrFail();

        $this->assertSame($employee->id, $candidate->fresh()->converted_employee_id);
        $this->assertSame('Calon Satu', $employee->full_name);
        $this->assertSame('0811111111', $employee->phone);
        $this->assertSame($this->dept->id, $employee->department_id);
        $this->assertSame($this->pos->id, $employee->position_id);
        $this->assertSame(EmploymentStatus::Kontrak, $employee->employment_status);
        $this->assertTrue($employee->is_active);
        $this->assertNull($employee->user_id);

        $this->assertTrue(
            AuditLog::where('action', 'employee.created')
                ->where('auditable_id', $employee->id)
                ->where('user_id', $this->hr->id)
                ->exists()
        );

        $this->actAs($this->hr)->getJson("/api/job-openings/{$this->opening->id}")
            ->assertOk()
            ->assertJsonPath('data.hired_count', 1)
            ->assertJsonPath('data.candidates_count', 1);
    }

    public function test_conversion_guards(): void
    {
        $before = Employee::count();

        $offer = $this->makeCandidate(CandidateStage::Offer, null, 'o@example.com');
        $this->actAs($this->hr)
            ->postJson("/api/candidates/{$offer->id}/convert", $this->convertPayload())
            ->assertStatus(409);

        $accepted = $this->makeCandidate(CandidateStage::Accepted, null, 'p@example.com');
        $this->actAs($this->hr)
            ->postJson("/api/candidates/{$accepted->id}/convert", $this->convertPayload(['employee_number' => 'EMP-901']))
            ->assertOk();

        $this->actAs($this->hr)
            ->postJson("/api/candidates/{$accepted->id}/convert", $this->convertPayload(['employee_number' => 'EMP-902']))
            ->assertStatus(409);

        $this->assertSame($before + 1, Employee::count());
        $this->assertNull($offer->fresh()->converted_employee_id);
    }

    public function test_conversion_respects_headcount_unique_number_and_manager_rule(): void
    {
        $tight = $this->makeOpening(1, 'Satu Posisi');
        $a = $this->makeCandidate(CandidateStage::Accepted, $tight, 'a@example.com');
        $b = $this->makeCandidate(CandidateStage::Accepted, $tight, 'b@example.com');

        $this->actAs($this->hr)
            ->postJson("/api/candidates/{$a->id}/convert", $this->convertPayload(['employee_number' => 'EMP-901']))
            ->assertOk();

        $this->actAs($this->hr)
            ->postJson("/api/candidates/{$b->id}/convert", $this->convertPayload(['employee_number' => 'EMP-902']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('job_opening_id');

        $this->assertDatabaseMissing('employees', ['employee_number' => 'EMP-902']);

        $c = $this->makeCandidate(CandidateStage::Accepted, null, 'c@example.com');

        $this->actAs($this->hr)
            ->postJson("/api/candidates/{$c->id}/convert", $this->convertPayload(['employee_number' => 'E1']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('employee_number');

        $this->actAs($this->hr)
            ->postJson("/api/candidates/{$c->id}/convert", $this->convertPayload(['manager_id' => $this->karyawanEmp->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('manager_id');

        $this->assertNull($c->fresh()->converted_employee_id);

        $this->actAs($this->hr)
            ->postJson("/api/candidates/{$c->id}/convert", $this->convertPayload(['manager_id' => $this->atasanEmp->id]))
            ->assertOk();

        $this->assertSame(
            $this->atasanEmp->id,
            Employee::where('employee_number', 'EMP-900')->firstOrFail()->manager_id
        );
    }

    public function test_contact_details_are_editable_until_converted(): void
    {
        $candidate = $this->makeCandidate(CandidateStage::Applied, null, 'a@example.com');
        $this->makeCandidate(CandidateStage::Applied, null, 'b@example.com');

        $this->actAs($this->hr)->putJson("/api/candidates/{$candidate->id}", [
            'full_name' => 'Calon Baru',
            'email' => 'a@example.com',
            'phone' => '0822222222',
        ])->assertOk()
            ->assertJsonPath('data.full_name', 'Calon Baru')
            ->assertJsonPath('data.phone', '0822222222');

        $log = AuditLog::where('action', 'candidate.updated')->where('auditable_id', $candidate->id)->firstOrFail();
        $this->assertSame($this->hr->id, $log->user_id);
        $this->assertSame('Calon Baru', $log->new_values['full_name']);
        $this->assertSame('[disembunyikan]', $log->new_values['phone']);

        $this->actAs($this->hr)->putJson("/api/candidates/{$candidate->id}", [
            'full_name' => 'Calon Baru',
            'email' => 'b@example.com',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $accepted = $this->makeCandidate(CandidateStage::Accepted, null, 'd@example.com');
        $this->actAs($this->hr)
            ->postJson("/api/candidates/{$accepted->id}/convert", $this->convertPayload())
            ->assertOk();

        $this->actAs($this->hr)->putJson("/api/candidates/{$accepted->id}", [
            'full_name' => 'Nama Lain',
            'email' => 'd@example.com',
        ])->assertStatus(409);
    }

    public function test_candidate_list_filters(): void
    {
        $other = $this->makeOpening(1, 'Lowongan Lain');

        $this->makeCandidate(CandidateStage::Applied, null, 'a@x.com');
        $this->makeCandidate(CandidateStage::Offer, null, 'b@x.com');
        $this->makeCandidate(CandidateStage::Applied, $other, 'c@x.com');

        $this->actAs($this->hr)->getJson('/api/candidates')->assertOk()->assertJsonCount(3, 'data');
        $this->actAs($this->hr)->getJson('/api/candidates?stage=offer')->assertOk()->assertJsonCount(1, 'data');
        $this->actAs($this->hr)->getJson("/api/candidates?job_opening_id={$other->id}")->assertOk()->assertJsonCount(1, 'data');
        $this->actAs($this->hr)->getJson('/api/candidates?search=b@x.com')->assertOk()->assertJsonCount(1, 'data');
        $this->actAs($this->hr)->getJson('/api/candidates?search=Calon')->assertOk()->assertJsonCount(3, 'data');
        $this->actAs($this->hr)->getJson('/api/candidates?stage=bukan-tahap')->assertUnprocessable();
    }
}
