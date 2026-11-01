<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $canSeeSensitive = Gate::forUser($request->user())->allows('viewSensitive', $this->resource);

        return [
            'id' => $this->id,
            'employee_number' => $this->employee_number,
            'full_name' => $this->full_name,
            'employment_status' => $this->employment_status->value,
            'hire_date' => $this->hire_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'is_active' => $this->is_active,
            'manager_id' => $this->manager_id,
            'department' => $this->whenLoaded('department', fn () => [
                'id' => $this->department->id,
                'name' => $this->department->name,
            ]),
            'position' => $this->whenLoaded('position', fn () => [
                'id' => $this->position->id,
                'name' => $this->position->name,
            ]),
            // Field sensitif cuma keluar kalau policy mengizinkan.
            $this->mergeWhen($canSeeSensitive, fn () => [
                'nik' => $this->nik,
                'phone' => $this->phone,
                'birth_date' => $this->birth_date?->toDateString(),
                'address' => $this->address,
            ]),
        ];
    }
}
