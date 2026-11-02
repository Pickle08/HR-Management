<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JobOpeningResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $hasStats = array_key_exists('hired_count', $this->resource->getAttributes());

        return [
            'id' => $this->id,
            'title' => $this->title,
            'department' => $this->whenLoaded('department', fn () => [
                'id' => $this->department->id,
                'name' => $this->department->name,
            ]),
            'position' => $this->whenLoaded('position', fn () => [
                'id' => $this->position->id,
                'name' => $this->position->name,
            ]),
            'headcount' => $this->headcount,
            'description' => $this->description,
            'status' => $this->status->value,
            'closed_at' => $this->closed_at?->toIso8601String(),
            $this->mergeWhen($hasStats, fn () => [
                'candidates_count' => (int) $this->candidates_count,
                'hired_count' => (int) $this->hired_count,
            ]),
        ];
    }
}
