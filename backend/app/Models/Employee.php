<?php

namespace App\Models;

use App\Enums\EmploymentStatus;
use App\Enums\Role;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    use Auditable;

    // user_id sengaja TIDAK fillable: menautkan karyawan ke akun = memberi akses data.
    protected $fillable = [
        'manager_id',
        'department_id',
        'position_id',
        'employee_number',
        'full_name',
        'nik',
        'phone',
        'birth_date',
        'hire_date',
        'end_date',
        'employment_status',
        'address',
    ];

    // Aman secara default: nik tidak ikut keluar di JSON kecuali diminta eksplisit.
    protected $hidden = ['nik'];

    protected function casts(): array
    {
        return [
            'nik' => 'encrypted',
            'phone' => 'encrypted',
            'birth_date' => 'date',
            'hire_date' => 'date',
            'end_date' => 'date',
            'employment_status' => EmploymentStatus::class,
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    public function subordinates(): HasMany
    {
        return $this->hasMany(self::class, 'manager_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $own = $user->employee;

        return match ($user->role) {
            Role::Hr, Role::SuperAdmin => $query,
            Role::Atasan => $own
                ? $query->where(fn($q) => $q->where('manager_id', $own->id)->orWhere('id', $own->id))
                : $query->whereRaw('1 = 0'),
            Role::Karyawan => $own
                ? $query->where('id', $own->id)
                : $query->whereRaw('1 = 0'),
            default => $query->whereRaw('1 = 0'),
        };
    }
}
