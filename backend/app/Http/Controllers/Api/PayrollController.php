<?php

namespace App\Http\Controllers\Api;

use App\Enums\PayrollStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\SavePayrollItemsRequest;
use App\Http\Requests\StoreAdjustmentRequest;
use App\Http\Requests\StorePayrollRequest;
use App\Http\Resources\PayrollResource;
use App\Models\Payroll;
use App\Services\PayrollService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class PayrollController extends Controller
{
    private const WITH = ['employee:id,employee_number,full_name', 'finalizer:id,name'];

    public function __construct(private PayrollService $payrolls)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Payroll::class);

        $filters = $request->validate([
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'employee_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(PayrollStatus::class)],
        ]);

        $payrolls = Payroll::query()
            ->visibleTo($request->user())
            ->withTotals()
            ->with(self::WITH)
            ->when($filters['year'] ?? null, fn($q, $v) => $q->where('year', $v))
            ->when($filters['month'] ?? null, fn($q, $v) => $q->where('month', $v))
            ->when($filters['employee_id'] ?? null, fn($q, $v) => $q->where('employee_id', $v))
            ->when($filters['status'] ?? null, fn($q, $v) => $q->where('status', $v))
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->orderBy('employee_id')
            ->orderBy('revision_no')
            ->paginate(20)
            ->withQueryString();

        return PayrollResource::collection($payrolls);
    }

    public function show(Payroll $payroll): PayrollResource
    {
        Gate::authorize('view', $payroll);

        return $this->respond($payroll);
    }

    public function store(StorePayrollRequest $request): PayrollResource
    {
        return $this->respond($this->payrolls->create($request->user(), $request->validated()));
    }

    public function updateItems(SavePayrollItemsRequest $request, Payroll $payroll): PayrollResource
    {
        return $this->respond(
            $this->payrolls->replaceItems($payroll, $request->user(), $request->validated()['items'])
        );
    }

    public function finalize(Request $request, Payroll $payroll): PayrollResource
    {
        Gate::authorize('finalize', $payroll);

        return $this->respond($this->payrolls->finalize($payroll, $request->user()));
    }

    public function adjust(StoreAdjustmentRequest $request, Payroll $payroll): PayrollResource
    {
        $data = $request->validated();

        return $this->respond(
            $this->payrolls->createAdjustment($payroll, $request->user(), $data['note'], $data['items'])
        );
    }

    public function destroy(Payroll $payroll): Response
    {
        Gate::authorize('delete', $payroll);

        $this->payrolls->delete($payroll);

        return response()->noContent();
    }

    private function respond(Payroll $payroll): PayrollResource
    {
        return new PayrollResource($payroll->load([
            ...self::WITH,
            'items' => fn($q) => $q->orderBy('id'),
        ]));
    }
}
