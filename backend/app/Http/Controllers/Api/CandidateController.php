<?php

namespace App\Http\Controllers\Api;

use App\Enums\CandidateStage;
use App\Http\Controllers\Controller;
use App\Http\Requests\ConvertCandidateRequest;
use App\Http\Requests\SaveCandidateRequest;
use App\Http\Resources\CandidateResource;
use App\Models\Candidate;
use App\Models\JobOpening;
use App\Services\RecruitmentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CandidateController extends Controller
{
    public function __construct(private RecruitmentService $recruitment)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('manage', Candidate::class);

        $filters = $request->validate([
            'job_opening_id' => ['nullable', 'integer'],
            'stage' => ['nullable', Rule::enum(CandidateStage::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        // phone terenkripsi, jadi pencarian cuma lewat nama dan email.
        $candidates = Candidate::query()
            ->with('jobOpening:id,title')
            ->when($filters['job_opening_id'] ?? null, fn ($q, $v) => $q->where('job_opening_id', $v))
            ->when($filters['stage'] ?? null, fn ($q, $v) => $q->where('stage', $v))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(
                fn ($q) => $q->where('full_name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")
            ))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return CandidateResource::collection($candidates);
    }

    public function show(Candidate $candidate): CandidateResource
    {
        Gate::authorize('manage', Candidate::class);

        return $this->respond($candidate);
    }

    public function store(SaveCandidateRequest $request): CandidateResource
    {
        $data = $request->validated();
        $opening = JobOpening::findOrFail($data['job_opening_id']);

        return $this->respond($this->recruitment->addCandidate($request->user(), $opening, $data));
    }

    public function update(SaveCandidateRequest $request, Candidate $candidate): CandidateResource
    {
        return $this->respond($this->recruitment->updateContact($candidate, $request->validated()));
    }

    public function move(Request $request, Candidate $candidate): CandidateResource
    {
        Gate::authorize('manage', Candidate::class);

        $data = $request->validate([
            'to_stage' => ['required', Rule::enum(CandidateStage::class)],
            'note' => [
                Rule::requiredIf($request->input('to_stage') === CandidateStage::Rejected->value),
                'nullable', 'string', 'max:500',
            ],
        ]);

        return $this->respond($this->recruitment->moveStage(
            $request->user(),
            $candidate,
            CandidateStage::from($data['to_stage']),
            $data['note'] ?? null,
        ));
    }

    public function convert(ConvertCandidateRequest $request, Candidate $candidate): CandidateResource
    {
        return $this->respond($this->recruitment->convert($candidate, $request->validated()));
    }

    private function respond(Candidate $candidate): CandidateResource
    {
        return new CandidateResource($candidate->load([
            'jobOpening:id,title',
            'histories' => fn ($q) => $q->orderBy('id')->with('changer:id,name'),
        ]));
    }
}
