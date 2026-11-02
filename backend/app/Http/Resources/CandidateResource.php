<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CandidateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'job_opening' => $this->whenLoaded('jobOpening', fn () => [
                'id' => $this->jobOpening->id,
                'title' => $this->jobOpening->title,
            ]),
            'full_name' => $this->full_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'stage' => $this->stage->value,
            'converted_employee_id' => $this->converted_employee_id,
            'history' => $this->whenLoaded('histories', fn () => $this->histories->map(fn ($h) => [
                'from_stage' => $h->from_stage,
                'to_stage' => $h->to_stage,
                'by' => $h->changer ? ['id' => $h->changer->id, 'name' => $h->changer->name] : null,
                'at' => $h->created_at->toIso8601String(),
                'note' => $h->note,
            ])->values()),
        ];
    }
}
