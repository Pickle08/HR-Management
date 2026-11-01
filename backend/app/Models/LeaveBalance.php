<?php

namespace App\Models;

use Closure;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    public function requests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class, 'employee_id', 'employee_id');
    }

    /** Jumlah hari approved dan pending untuk karyawan/jenis/tahun baris ini. */
    public function scopeWithUsage(Builder $query): Builder
    {
        return $query
            ->withSum(['requests as approved_days' => self::usageConstraint('approved')], 'days')
            ->withSum(['requests as pending_days' => self::usageConstraint('pending')], 'days');
    }

    public function loadUsage(): static
    {
        return $this
            ->loadSum(['requests as approved_days' => self::usageConstraint('approved')], 'days')
            ->loadSum(['requests as pending_days' => self::usageConstraint('pending')], 'days');
    }

    private static function usageConstraint(string $status): Closure
    {
        return fn($q) => $q
            ->where('leave_requests.status', $status)
            ->whereColumn('leave_requests.leave_type_id', 'leave_balances.leave_type_id')
            ->whereColumn('leave_requests.year', 'leave_balances.year');
    }
}
