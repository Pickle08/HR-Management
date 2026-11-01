<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaveRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
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
        ];
    }
}
