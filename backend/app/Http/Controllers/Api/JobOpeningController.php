<?php

namespace App\Http\Controllers\Api;

use App\Enums\JobOpeningStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveJobOpeningRequest;
use App\Http\Resources\JobOpeningResource;
use App\Models\JobOpening;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class JobOpeningController extends Controller
{
    private const WITH = ['department:id,name', 'position:id,name'];

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('manage', JobOpening::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(JobOpeningStatus::class)],
        ]);

        $openings = JobOpening::query()
            ->withStats()
            ->with(self::WITH)
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return JobOpeningResource::collection($openings);
    }

    public function show(JobOpening $jobOpening): JobOpeningResource
    {
        Gate::authorize('manage', JobOpening::class);

        return $this->respond($jobOpening);
    }

    public function store(SaveJobOpeningRequest $request): JobOpeningResource
    {
        $opening = new JobOpening($request->validated());
        $opening->forceFill([
            'status' => JobOpeningStatus::Open,
            'created_by' => $request->user()->id,
        ])->save();

        return $this->respond($opening);
    }

    public function update(SaveJobOpeningRequest $request, JobOpening $jobOpening): JobOpeningResource
    {
        $data = $request->validated();
        $hired = $jobOpening->candidates()->whereNotNull('converted_employee_id')->count();

        if ($data['headcount'] < $hired) {
            throw ValidationException::withMessages([
                'headcount' => ["Kuota tidak boleh kurang dari jumlah yang sudah direkrut ({$hired})."],
            ]);
        }

        $jobOpening->update($data);

        return $this->respond($jobOpening);
    }

    public function close(JobOpening $jobOpening): JobOpeningResource
    {
        Gate::authorize('manage', JobOpening::class);

        $opening = DB::transaction(function () use ($jobOpening) {
            $locked = JobOpening::whereKey($jobOpening->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== JobOpeningStatus::Open) {
                abort(409, 'Lowongan sudah ditutup.');
            }

            $locked->forceFill(['status' => JobOpeningStatus::Closed, 'closed_at' => now()])->save();

            return $locked;
        });

        return $this->respond($opening);
    }

    private function respond(JobOpening $opening): JobOpeningResource
    {
        return new JobOpeningResource($opening->load(self::WITH)->loadStats());
    }
}
