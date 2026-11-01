<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

class AttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Catatan bisa berisi info kesehatan: cuma HR dan pemilik data.
        $canSeeNote = Gate::forUser($request->user())->allows('viewSensitive', $this->employee);

        return [
            'id' => $this->id,
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee->id,
                'employee_number' => $this->employee->employee_number,
                'full_name' => $this->employee->full_name,
            ]),
            'date' => $this->date,
            'status' => $this->status->value,
            'check_in' => $this->check_in ? substr($this->check_in, 0, 5) : null,
            'check_out' => $this->check_out ? substr($this->check_out, 0, 5) : null,
            $this->mergeWhen($canSeeNote, fn () => ['note' => $this->note]),
        ];
    }
}
