<?php

namespace App\Http\Resources;

use App\Enums\LeaveStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

class LeaveRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $gate = Gate::forUser($user);

        // Policy decide() membaca manager_id. Kalau relasi dimuat sebagian tanpa kolom itu,
        // muat penuh dulu: lebih baik satu query tambahan daripada jawaban izin yang salah.
        $employee = $this->resource->employee;

        if ($employee && ! array_key_exists('manager_id', $employee->getAttributes())) {
            $this->resource->load('employee');
        }

        $pending = $this->status === LeaveStatus::Pending;
        $isOwner = $user?->employee?->id === $this->employee_id;

        return [
            'id' => $this->id,
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee->id,
                'employee_number' => $this->employee->employee_number,
                'full_name' => $this->employee->full_name,
            ]),
            'leave_type' => $this->whenLoaded('leaveType', fn () => [
                'id' => $this->leaveType->id,
                'name' => $this->leaveType->name,
            ]),
            'year' => $this->year,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'days' => $this->days,
            'reason' => $this->reason,
            'status' => $this->status->value,
            'decision' => $this->when($this->decided_at !== null, fn () => [
                'by' => $this->decider ? ['id' => $this->decider->id, 'name' => $this->decider->name] : null,
                'at' => $this->decided_at->toIso8601String(),
                'note' => $this->decision_note,
            ]),
            'cancellation' => $this->when($this->cancelled_at !== null, fn () => [
                'by' => $this->canceller ? ['id' => $this->canceller->id, 'name' => $this->canceller->name] : null,
                'at' => $this->cancelled_at->toIso8601String(),
                'note' => $this->cancellation_note,
            ]),
            // Petunjuk untuk tombol di layar. Penjaga sebenarnya tetap policy dan LeaveService di server.
            'can_decide' => $pending && $gate->allows('decide', $this->resource),
            'can_cancel' => ($pending || ($this->status === LeaveStatus::Approved && ! $isOwner))
                && $gate->allows('cancel', $this->resource),
        ];
    }
}
