<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveBalance extends Model
{
    use Auditable;

    protected $fillable = ['employee_id', 'leave_type_id', 'year', 'entitled_days'];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'entitled_days' => 'integer',
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

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->whereIn('employee_id', Employee::query()->visibleTo($user)->select('id'));
    }
}
