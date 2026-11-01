<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    use Auditable;

    // recorded_by sengaja TIDAK fillable: diisi server dari user yang login.
    protected $fillable = [
        'employee_id',
        'date',
        'status',
        'check_in',
        'check_out',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'status' => AttendanceStatus::class,
        ];
    }

    // Normalisasi "08:00" jadi "08:00:00", supaya kiriman ulang nilai yang sama
    // tidak dianggap perubahan (dan tidak mengotori audit log).
    protected function checkIn(): Attribute
    {
        return Attribute::make(set: fn (?string $v) => self::normalizeTime($v));
    }

    protected function checkOut(): Attribute
    {
        return Attribute::make(set: fn (?string $v) => self::normalizeTime($v));
    }

    private static function normalizeTime(?string $value): ?string
    {
        return $value === null ? null : date('H:i:s', strtotime($value));
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->whereIn('employee_id', Employee::query()->visibleTo($user)->select('id'));
    }
}
