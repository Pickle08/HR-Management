<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveAttendanceRequest;
use App\Http\Resources\AttendanceResource;
use App\Models\Attendance;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AttendanceController extends Controller
{
    private const WITH = 'employee:id,employee_number,full_name';

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Attendance::class);

        $filters = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'employee_id' => ['nullable', 'integer'],
        ]);

        $attendances = Attendance::query()
            ->visibleTo($request->user())
            ->with(self::WITH)
            ->when($filters['date'] ?? null, fn ($q, $v) => $q->where('date', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('date', '<=', $v))
            ->when($filters['employee_id'] ?? null, fn ($q, $v) => $q->where('employee_id', $v))
            ->orderByDesc('date')
            ->orderBy('employee_id')
            ->paginate(20)
            ->withQueryString();

        return AttendanceResource::collection($attendances);
    }

    public function show(Attendance $attendance): AttendanceResource
    {
        Gate::authorize('view', $attendance);

        return new AttendanceResource($attendance->load(self::WITH));
    }

    public function store(SaveAttendanceRequest $request): AttendanceResource
    {
        $attendance = new Attendance($request->validated());
        $attendance->recorded_by = $request->user()->id;

        try {
            $attendance->save();
        } catch (UniqueConstraintViolationException) {
            // Penjaga sebenarnya adalah unique index di database.
            throw ValidationException::withMessages([
                'date' => ['Absensi karyawan ini pada tanggal tersebut sudah ada. Gunakan koreksi.'],
            ]);
        }

        return new AttendanceResource($attendance->load(self::WITH));
    }

    public function update(SaveAttendanceRequest $request, Attendance $attendance): AttendanceResource
    {
        // PUT = ganti penuh: field opsional yang tidak dikirim di-reset,
        // supaya status "sakit" tidak menyisakan jam masuk lama.
        $attendance->update(array_merge(
            ['check_in' => null, 'check_out' => null, 'note' => null],
            $request->validated(),
        ));

        return new AttendanceResource($attendance->load(self::WITH));
    }
}
