<?php

namespace App\Models;

use App\Enums\EmploymentStatus;
use App\Models\Concerns\Auditable;
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
        'is_active',
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
}
