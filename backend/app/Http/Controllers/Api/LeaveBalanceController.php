<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SetLeaveBalanceRequest;
use App\Http\Resources\LeaveBalanceResource;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class LeaveBalanceController extends Controller
{
    private const WITH = ['employee:id,employee_number,full_name', 'leaveType:id,name'];

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', LeaveBalance::class);

        $filters = $request->validate([
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'employee_id' => ['nullable', 'integer'],
        ]);

        $balances = LeaveBalance::query()
            ->visibleTo($request->user())
            ->with(self::WITH)
            ->where('year', $filters['year'] ?? now()->year)
            ->when($filters['employee_id'] ?? null, fn ($q, $v) => $q->where('employee_id', $v))
            ->orderBy('employee_id')
            ->orderBy('leave_type_id')
            ->paginate(20)
            ->withQueryString();

        return LeaveBalanceResource::collection($balances);
    }

    /** Atur jatah satu karyawan/jenis/tahun. Dipanggil ulang = mengubah jatah, tercatat di audit. */
    public function set(SetLeaveBalanceRequest $request): LeaveBalanceResource
    {
        $data = $request->validated();

        $balance = LeaveBalance::updateOrCreate(
            Arr::only($data, ['employee_id', 'leave_type_id', 'year']),
            ['entitled_days' => $data['entitled_days']],
        );

        return new LeaveBalanceResource($balance->load(self::WITH));
    }

    /** Bikin jatah satu tahun untuk semua karyawan aktif x jenis cuti aktif. Aman dipanggil berulang. */
    public function generate(Request $request): JsonResponse
    {
        Gate::authorize('manage', LeaveBalance::class);

        $data = $request->validate([
            'year' => ['required', 'integer', 'between:2000,2100'],
        ]);

        $created = 0;

        DB::transaction(function () use ($data, &$created) {
            $types = LeaveType::where('is_active', true)->get();

            Employee::where('is_active', true)->each(function (Employee $employee) use ($types, $data, &$created) {
                foreach ($types as $type) {
                    $balance = LeaveBalance::firstOrCreate(
                        [
                            'employee_id' => $employee->id,
                            'leave_type_id' => $type->id,
                            'year' => $data['year'],
                        ],
                        ['entitled_days' => $type->annual_quota],
                    );

                    if ($balance->wasRecentlyCreated) {
                        $created++;
                    }
                }
            });
        });

        return response()->json(['created' => $created]);
    }
}
