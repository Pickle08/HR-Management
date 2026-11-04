<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;

class EmployeeController extends Controller
{
    private const WITH = [
        'department:id,name',
        'position:id,name',
        'manager:id,full_name',
        'province:code,name',
        'regency:code,name',
        'district:code,name',
        'village:code,name',
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Employee::class);

        // nik/phone terenkripsi, jadi pencarian cuma lewat nama dan nomor karyawan.
        $search = trim((string) $request->query('search'));

        $employees = Employee::query()
            ->visibleTo($request->user())
            ->with(self::WITH)
            ->when($search !== '', fn($q) => $q->where(
                fn($q) => $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('employee_number', 'like', "%{$search}%")
            ))
            ->orderBy('full_name')
            ->paginate(15)
            ->withQueryString();

        return EmployeeResource::collection($employees);
    }

    /** Pilihan atasan untuk formulir: persis kriteria ActiveManager, hanya field minimal. */
    public function managers(Request $request): JsonResponse
    {
        Gate::authorize('create', Employee::class);

        $search = trim((string) $request->query('search'));

        $managers = Employee::query()
            ->where('is_active', true)
            ->whereHas('user', fn ($q) => $q->where('is_active', true)->where('role', Role::Atasan->value))
            ->when($search !== '', fn ($q) => $q->where(
                fn ($q) => $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('employee_number', 'like', "%{$search}%")
            ))
            ->orderBy('full_name')
            ->limit(50)
            ->get(['id', 'employee_number', 'full_name']);

        return response()->json(['data' => $managers]);
    }

    public function show(Employee $employee): EmployeeResource
    {
        Gate::authorize('view', $employee);

        return new EmployeeResource($employee->load(self::WITH));
    }

    public function store(SaveEmployeeRequest $request): EmployeeResource
    {
        $employee = Employee::create($request->validated());

        return new EmployeeResource($employee->load(self::WITH));
    }

    public function update(SaveEmployeeRequest $request, Employee $employee): EmployeeResource
    {
        $employee->update($request->validated());

        return new EmployeeResource($employee->load(self::WITH));
    }

    public function deactivate(Employee $employee): EmployeeResource
    {
        return $this->setActive($employee, false);
    }

    public function activate(Employee $employee): EmployeeResource
    {
        return $this->setActive($employee, true);
    }

    private function setActive(Employee $employee, bool $active): EmployeeResource
    {
        Gate::authorize('update', $employee);

        if (!$active && $employee->user_id !== null && $employee->user_id === request()->user()->id) {
            abort(422, 'Tidak bisa menonaktifkan akun sendiri.');
        }

        if (!$active && $employee->subordinates()->where('is_active', true)->exists()) {
            abort(409, 'Karyawan ini masih punya bawahan aktif. Pindahkan bawahannya dulu.');
        }

        DB::transaction(function () use ($employee, $active) {
            $employee->forceFill(['is_active' => $active])->save();

            // Karyawan nonaktif = akun login terkunci. Mengaktifkan lagi TIDAK
            // membuka akun otomatis; itu harus dilakukan sengaja.
            if (!$active && $employee->user) {
                $employee->user->forceFill(['is_active' => false])->save();
            }
        });

        return new EmployeeResource($employee->load(self::WITH));
    }
}
