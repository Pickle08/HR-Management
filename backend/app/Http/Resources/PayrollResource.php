<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayrollResource extends JsonResource
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
            'year' => $this->year,
            'month' => $this->month,
            'revision_no' => $this->revision_no,
            'adjusts_payroll_id' => $this->adjusts_payroll_id,
            'status' => $this->status->value,
            'note' => $this->note,
            'totals' => $this->totals(),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'type' => $item->type->value,
                'label' => $item->label,
                'amount' => $item->amount,
            ])->values()),
            'finalization' => $this->when($this->finalized_at !== null, fn () => [
                'by' => $this->finalizer ? ['id' => $this->finalizer->id, 'name' => $this->finalizer->name] : null,
                'at' => $this->finalized_at->toIso8601String(),
            ]),
        ];
    }
}
