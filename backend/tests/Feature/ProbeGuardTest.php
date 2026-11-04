<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\JobOpening;
use App\Support\ProbeGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ProbeGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guard_only_allows_mysql_databases_ending_in_probe(): void
    {
        $this->assertTrue(ProbeGuard::allows('mysql', 'hrm_probe'));

        $this->assertFalse(ProbeGuard::allows('mysql', 'hrm'));
        $this->assertFalse(ProbeGuard::allows('mysql', 'probe'));
        $this->assertFalse(ProbeGuard::allows('mysql', 'hrm_probe_lama'));
        $this->assertFalse(ProbeGuard::allows('sqlite', 'hrm_probe'));
        $this->assertFalse(ProbeGuard::allows('sqlite', ':memory:'));
    }

    public function test_probe_commands_refuse_to_run_here_and_create_nothing(): void
    {
        $this->assertSame(1, Artisan::call('hrm:probe-concurrency'));
        $this->assertSame(1, Artisan::call('hrm:probe-worker', [
            'candidate' => 1,
            'number' => 'X',
            'start' => '0',
        ]));

        $this->assertSame(0, JobOpening::count());
        $this->assertSame(0, Candidate::count());
    }
}
