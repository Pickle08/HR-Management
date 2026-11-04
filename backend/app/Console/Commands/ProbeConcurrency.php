<?php

namespace App\Console\Commands;

use App\Enums\CandidateStage;
use App\Enums\JobOpeningStatus;
use App\Models\Candidate;
use App\Models\Department;
use App\Models\JobOpening;
use App\Models\Position;
use App\Support\ProbeGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProbeConcurrency extends Command
{
    protected $signature = 'hrm:probe-concurrency
        {--rounds=5 : Jumlah putaran}
        {--lead=6 : Detik jeda sebelum kedua proses mulai bersamaan}';

    protected $description = 'Uji manual: dua proses paralel mengonversi kandidat ke lowongan berkuota 1 (hanya MySQL, database *_probe)';

    public function handle(): int
    {
        $connection = DB::connection();

        if (! ProbeGuard::allows($connection->getDriverName(), (string) $connection->getDatabaseName())) {
            $this->error('Ditolak: hanya jalan di MySQL dengan nama database berakhiran _probe (bukan produksi), supaya data asli tidak tersentuh.');

            return self::FAILURE;
        }

        $rounds = max(1, (int) $this->option('rounds'));
        $lead = max(3.0, (float) $this->option('lead'));
        $run = Str::lower(Str::random(5));

        $department = Department::firstOrCreate(['name' => 'Probe']);
        $position = Position::firstOrCreate(['name' => 'Probe']);

        $rows = [];
        $violations = 0;
        $invalid = 0;

        for ($round = 1; $round <= $rounds; $round++) {
            $opening = JobOpening::forceCreate([
                'title' => "Probe {$run}-{$round}",
                'department_id' => $department->id,
                'position_id' => $position->id,
                'headcount' => 1,
                'status' => JobOpeningStatus::Open,
            ]);

            $ids = [];

            foreach ([1, 2] as $n) {
                $ids[$n] = Candidate::forceCreate([
                    'job_opening_id' => $opening->id,
                    'full_name' => "Calon {$run}-{$round}-{$n}",
                    'email' => "probe{$run}{$round}{$n}@example.com",
                    'stage' => CandidateStage::Accepted,
                ])->id;
            }

            $start = microtime(true) + $lead;
            $procs = [];

            foreach ($ids as $n => $id) {
                $command = [
                    PHP_BINARY, base_path('artisan'), 'hrm:probe-worker',
                    (string) $id, "PRB-{$run}-{$round}-{$n}", sprintf('%.6F', $start),
                ];

                $proc = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $procs[$n] = [$proc, $pipes];
            }

            $results = [];

            foreach ($procs as $n => [$proc, $pipes]) {
                $out = stream_get_contents($pipes[1]);
                $err = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);

                $results[$n] = $this->parse($out, $err);
            }

            $hired = Candidate::where('job_opening_id', $opening->id)->whereNotNull('converted_employee_id')->count();
            $statuses = array_column($results, 'status');
            $okCount = count(array_filter($statuses, fn ($s) => $s === 'ok'));

            $overlap = isset($results[1]['began'], $results[2]['began'], $results[1]['finished'], $results[2]['finished'])
                && max($results[1]['began'], $results[2]['began']) < min($results[1]['finished'], $results[2]['finished']);

            if ($okCount > 1 || $hired > 1) {
                $verdict = 'PELANGGARAN KUOTA';
                $violations++;
            } elseif (in_array('error', $statuses, true) || in_array('crash', $statuses, true)) {
                $verdict = 'ERROR (lihat detail)';
                $invalid++;
            } elseif (! $overlap) {
                $verdict = 'tidak bersamaan, tidak valid';
                $invalid++;
            } else {
                $verdict = 'ok';
            }

            $rows[] = [$round, $statuses[0] ?? '-', $statuses[1] ?? '-', $hired, $overlap ? 'ya' : 'tidak', $verdict];

            foreach ($results as $n => $result) {
                if (in_array($result['status'], ['error', 'crash'], true)) {
                    $this->warn("Putaran {$round}, proses {$n}: ".($result['detail'] ?? '-'));
                }
            }
        }

        $this->table(['Putaran', 'Proses A', 'Proses B', 'Terekrut', 'Bersamaan', 'Hasil'], $rows);

        if ($violations > 0) {
            $this->error("{$violations} putaran melanggar kuota: penguncian tidak bekerja seperti yang diharapkan.");

            return self::FAILURE;
        }

        if ($invalid > 0) {
            $this->warn("{$invalid} putaran tidak valid. Ulangi dengan --lead yang lebih besar (misalnya --lead=10).");

            return self::FAILURE;
        }

        $this->info("Tidak ada pelanggaran kuota dalam {$rounds} putaran bersamaan. Ini bukti terbatas, bukan jaminan.");

        return self::SUCCESS;
    }

    private function parse(string $out, string $err): array
    {
        foreach (array_reverse(preg_split('/\r?\n/', trim($out))) as $line) {
            $decoded = json_decode($line, true);

            if (is_array($decoded) && isset($decoded['status'])) {
                return $decoded;
            }
        }

        return ['status' => 'crash', 'detail' => trim($err !== '' ? $err : $out)];
    }
}
