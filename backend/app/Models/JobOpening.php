<?php

namespace App\Models;

use App\Enums\JobOpeningStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobOpening extends Model
{
    use Auditable;

    // status, closed_at, created_by diisi server.
    protected $fillable = ['title', 'department_id', 'position_id', 'headcount', 'description'];

    protected function casts(): array
    {
        return [
            'headcount' => 'integer',
            'status' => JobOpeningStatus::class,
            'closed_at' => 'datetime',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(Candidate::class);
    }

    public function scopeWithStats(Builder $query): Builder
    {
        return $query->withCount(self::stats());
    }

    public function loadStats(): static
    {
        return $this->loadCount(self::stats());
    }

    private static function stats(): array
    {
        return [
            'candidates',
            'candidates as hired_count' => fn ($q) => $q->whereNotNull('converted_employee_id'),
        ];
    }
}
