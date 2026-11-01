<?php

namespace App\Models;

use App\Enums\LeaveStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequest extends Model
{
    use Auditable;

    // Sisanya (employee_id, year, days, status, decided_*, cancelled_*) diisi server.
    protected $fillable = ['leave_type_id', 'start_date', 'end_date', 'reason'];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'days' => 'integer',
            'status' => LeaveStatus::class,
            'decided_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->whereIn('employee_id', Employee::query()->visibleTo($user)->select('id'));
    }
}
