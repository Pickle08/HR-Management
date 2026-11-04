<?php

namespace App\Console\Commands;

use App\Models\Candidate;
use App\Services\RecruitmentService;
use App\Support\ProbeGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ProbeWorker extends Command
{
    protected $signature = 'hrm:probe-worker {candidate} {number} {start}';

    protected $description = 'Dipanggil oleh hrm:probe-concurrency. Jangan dijalankan manual.';

    protected $hidden = true;

    public function handle(RecruitmentService $service): int
    {
        $connection = DB::connection();

        if (! ProbeGuard::allows($connection->getDriverName(), (string) $connection->getDatabaseName())) {
            $this->error('Ditolak: bukan database *_probe di MySQL.');

            return self::FAILURE;
        }

        // Semua proses menunggu detik yang sama supaya benar-benar berebut.
        $start = (float) $this->argument('start');

        while (microtime(true) < $start) {
            usleep(500);
        }

        $began = microtime(true);

        try {
            $service->convert(Candidate::findOrFail((int) $this->argument('candidate')), [
                'employee_number' => (string) $this->argument('number'),
                'hire_date' => '2030-01-01',
                'employment_status' => 'tetap',
            ]);

            $result = ['status' => 'ok'];
        } catch (ValidationException $e) {
            $result = ['status' => 'rejected', 'detail' => array_key_first($e->errors())];
        } catch (HttpExceptionInterface $e) {
            $result = ['status' => 'rejected', 'detail' => $e->getStatusCode()];
        } catch (Throwable $e) {
            $result = ['status' => 'error', 'detail' => $e::class.': '.$e->getMessage()];
        }

        $this->line(json_encode($result + ['began' => $began, 'finished' => microtime(true)]));

        return self::SUCCESS;
    }
}
