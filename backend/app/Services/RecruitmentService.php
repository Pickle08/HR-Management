<?php

namespace App\Services;

use App\Enums\CandidateStage;
use App\Enums\JobOpeningStatus;
use App\Models\Candidate;
use App\Models\Employee;
use App\Models\JobOpening;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecruitmentService
{
    public function addCandidate(User $actor, JobOpening $opening, array $data): Candidate
    {
        return DB::transaction(function () use ($actor, $opening, $data) {
            $locked = JobOpening::whereKey($opening->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== JobOpeningStatus::Open) {
                throw ValidationException::withMessages([
                    'job_opening_id' => ['Lowongan sudah ditutup.'],
                ]);
            }

            $candidate = new Candidate(Arr::only($data, ['full_name', 'email', 'phone']));
            $candidate->forceFill([
                'job_opening_id' => $locked->id,
                'stage' => CandidateStage::Applied,
            ]);

            try {
                $candidate->save();
            } catch (UniqueConstraintViolationException) {
                // Penjaga sebenarnya: unique(job_opening_id, email) di database.
                throw ValidationException::withMessages([
                    'email' => ['Kandidat dengan email ini sudah melamar lowongan ini.'],
                ]);
            }

            $candidate->histories()->forceCreate([
                'from_stage' => null,
                'to_stage' => CandidateStage::Applied->value,
                'changed_by' => $actor->id,
            ]);

            return $candidate;
        });
    }

    public function updateContact(Candidate $candidate, array $data): Candidate
    {
        return DB::transaction(function () use ($candidate, $data) {
            $locked = Candidate::whereKey($candidate->id)->lockForUpdate()->firstOrFail();

            if ($locked->converted_employee_id !== null) {
                abort(409, 'Kandidat sudah dikonversi menjadi karyawan. Ubah datanya di data karyawan.');
            }

            // PUT = ganti penuh: telepon yang tidak dikirim dikosongkan.
            $locked->fill(array_merge(['phone' => null], Arr::only($data, ['full_name', 'email', 'phone'])));

            try {
                $locked->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([
                    'email' => ['Kandidat dengan email ini sudah melamar lowongan ini.'],
                ]);
            }

            return $locked;
        });
    }

    public function moveStage(User $actor, Candidate $candidate, CandidateStage $to, ?string $note): Candidate
    {
        return DB::transaction(function () use ($actor, $candidate, $to, $note) {
            $locked = Candidate::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            $from = $locked->stage;

            if (! $from->canMoveTo($to)) {
                abort(409, "Kandidat di tahap {$from->value} tidak bisa dipindah ke {$to->value}.");
            }

            $locked->forceFill(['stage' => $to])->save();

            $locked->histories()->forceCreate([
                'from_stage' => $from->value,
                'to_stage' => $to->value,
                'changed_by' => $actor->id,
                'note' => $note,
            ]);

            return $locked;
        });
    }

    public function convert(Candidate $candidate, array $data): Candidate
    {
        return DB::transaction(function () use ($candidate, $data) {
            $locked = Candidate::whereKey($candidate->id)->lockForUpdate()->firstOrFail();

            if ($locked->converted_employee_id !== null) {
                abort(409, 'Kandidat ini sudah dikonversi menjadi karyawan.');
            }

            if ($locked->stage !== CandidateStage::Accepted) {
                abort(409, 'Hanya kandidat yang sudah menerima tawaran yang bisa dikonversi.');
            }

            // Kunci lowongan: dua konversi bersamaan tidak bisa sama-sama lolos cek kuota.
            $opening = JobOpening::whereKey($locked->job_opening_id)->lockForUpdate()->firstOrFail();

            $hired = Candidate::where('job_opening_id', $opening->id)
                ->whereNotNull('converted_employee_id')
                ->count();

            if ($hired >= $opening->headcount) {
                throw ValidationException::withMessages([
                    'job_opening_id' => ['Kuota lowongan sudah terpenuhi.'],
                ]);
            }

            $employee = new Employee(Arr::only($data, [
                'employee_number', 'manager_id', 'nik', 'birth_date',
                'hire_date', 'employment_status', 'address',
            ]));

            $employee->forceFill([
                'full_name' => $locked->full_name,
                'phone' => $locked->phone,
                'department_id' => $data['department_id'] ?? $opening->department_id,
                'position_id' => $data['position_id'] ?? $opening->position_id,
                'is_active' => true,
            ]);

            try {
                $employee->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([
                    'employee_number' => ['Nomor karyawan sudah dipakai.'],
                ]);
            }

            $locked->forceFill(['converted_employee_id' => $employee->id])->save();

            return $locked;
        });
    }
}
