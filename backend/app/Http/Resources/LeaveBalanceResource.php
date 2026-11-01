<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaveBalanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee' => $this->whenLoaded('employee', fn() => [
                'id' => $this->employee->id,
                'employee_number' => $this->employee->employee_number,
                'full_name' => $this->employee->full_name,
            ]),
            'leave_type' => $this->whenLoaded('leaveType', fn() => [
                'id' => $this->leaveType->id,
                'name' => $this->leaveType->name,
            ]),
            'year' => $this->year,
            'entitled_days' => $this->entitled_days,
            $this->mergeWhen(array_key_exists('approved_days', $this->resource->getAttributes()), fn() => [
                'used_days' => (int) $this->approved_days,
                'pending_days' => (int) $this->pending_days,
                'remaining_days' => $this->entitled_days - (int) $this->approved_days - (int) $this->pending_days,
            ]),
        ];
    }
}
