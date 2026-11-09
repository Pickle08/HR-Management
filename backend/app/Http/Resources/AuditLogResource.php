<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    /**
     * Nilai hanya ditampilkan untuk model dan kolom yang terdaftar di sini.
     * Model lain hanya menampilkan nama kolom yang berubah.
     */
    private const VISIBLE = [
        'Department' => ['name'],
        'Position' => ['name'],
        'LeaveType' => ['name', 'annual_quota', 'is_active'],
        'LeaveBalance' => ['employee_id', 'leave_type_id', 'year', 'entitled_days'],
        'User' => ['name', 'email', 'role', 'is_active'],
    ];

    public function toArray(Request $request): array
    {
        $type = $this->auditable_type ? class_basename($this->auditable_type) : null;
        $show = $type === null ? null : (self::VISIBLE[$type] ?? []);

        $old = $this->old_values;
        $new = $this->new_values;

        return [
            'id' => $this->id,
            'action' => $this->action,
            'created_at' => $this->created_at?->toIso8601String(),
            'actor' => $this->whenLoaded('actor', fn() => $this->actor ? [
                'id' => $this->actor->id,
                'name' => $this->actor->name,
                'email' => $this->actor->email,
            ] : null),
            'subject' => $type ? ['type' => $type, 'id' => $this->auditable_id] : null,
            'changed_fields' => array_values(array_unique([
                ...array_keys($old ?? []),
                ...array_keys($new ?? []),
            ])),
            'old_values' => $old === null ? null : $this->visible($old, $show),
            'new_values' => $new === null ? null : $this->visible($new, $show),
            'ip_address' => $this->ip_address,
        ];
    }

    private function visible(array $values, ?array $show): array
    {
        return $show === null ? $values : array_intersect_key($values, array_flip($show));
    }
}
