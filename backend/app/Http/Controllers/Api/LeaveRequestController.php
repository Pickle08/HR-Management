<?php

namespace App\Http\Controllers\Api;

use App\Enums\LeaveStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitLeaveRequest;
use App\Http\Resources\LeaveRequestResource;
use App\Models\LeaveRequest;
use App\Services\LeaveService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class LeaveRequestController extends Controller
{
    private const WITH = [
        'employee:id,employee_number,full_name,manager_id',
        'leaveType:id,name',
        'decider:id,name',
        'canceller:id,name',
    ];

    public function __construct(private LeaveService $leaves)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', LeaveRequest::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(LeaveStatus::class)],
            'employee_id' => ['nullable', 'integer'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
        ]);

        $requests = LeaveRequest::query()
            ->visibleTo($request->user())
            ->with(self::WITH)
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['employee_id'] ?? null, fn ($q, $v) => $q->where('employee_id', $v))
            ->when($filters['year'] ?? null, fn ($q, $v) => $q->where('year', $v))
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return LeaveRequestResource::collection($requests);
    }

    public function show(LeaveRequest $leaveRequest): LeaveRequestResource
    {
        Gate::authorize('view', $leaveRequest);

        return $this->respond($leaveRequest);
    }

    public function store(SubmitLeaveRequest $request): LeaveRequestResource
    {
        $leave = $this->leaves->submit($request->user()->employee, $request->validated());

        return $this->respond($leave);
    }

    public function approve(Request $request, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        Gate::authorize('decide', $leaveRequest);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return $this->respond(
            $this->leaves->decide($leaveRequest, $request->user(), LeaveStatus::Approved, $data['note'] ?? null)
        );
    }

    public function reject(Request $request, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        Gate::authorize('decide', $leaveRequest);

        $data = $request->validate(['note' => ['required', 'string', 'max:500']]);

        return $this->respond(
            $this->leaves->decide($leaveRequest, $request->user(), LeaveStatus::Rejected, $data['note'])
        );
    }

    public function cancel(Request $request, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        Gate::authorize('cancel', $leaveRequest);

        $isOwner = $request->user()->employee?->id === $leaveRequest->employee_id;

        // Pemilik boleh membatalkan tanpa alasan; HR/Super Admin wajib menyebut alasan.
        $data = $request->validate([
            'note' => [Rule::requiredIf(! $isOwner), 'nullable', 'string', 'max:500'],
        ]);

        return $this->respond(
            $this->leaves->cancel($leaveRequest, $request->user(), $data['note'] ?? null)
        );
    }

    private function respond(LeaveRequest $leave): LeaveRequestResource
    {
        return new LeaveRequestResource($leave->load(self::WITH));
    }
}
