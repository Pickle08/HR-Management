<?php

namespace App\Models;

use App\Enums\PayrollItemType;
use App\Enums\PayrollStatus;
use App\Enums\Role;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class Payroll extends Model
{
    use Auditable;

    // Sengaja tanpa $fillable: semua kolom diisi PayrollService lewat forceFill().

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'revision_no' => 'integer',
            'status' => PayrollStatus::class,
            'finalized_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Jaring pengaman terakhir. Jalur normal sudah ditolak lebih dulu (409) di service.
        static::updating(function (Payroll $payroll) {
            if ($payroll->getRawOriginal('status') === PayrollStatus::Finalized->value) {
                throw new LogicException('Payroll final tidak boleh diubah.');
            }
        });

        static::deleting(function (Payroll $payroll) {
            if ($payroll->getRawOriginal('status') === PayrollStatus::Finalized->value) {
                throw new LogicException('Payroll final tidak boleh dihapus.');
            }
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }

    public function adjusts(): BelongsTo
    {
        return $this->belongsTo(self::class, 'adjusts_payroll_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(self::class, 'adjusts_payroll_id');
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return match ($user->role) {
            Role::Hr, Role::Finance => $query,
            // Slip sendiri, dan hanya yang sudah final.
            Role::Atasan, Role::Karyawan => $user->employee
                ? $query->where('employee_id', $user->employee->id)
                    ->where('status', PayrollStatus::Finalized->value)
                : $query->whereRaw('1 = 0'),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /** Untuk daftar: hitung rekap di database, tanpa memuat semua item. */
    public function scopeWithTotals(Builder $query): Builder
    {
        return $query
            ->withSum(['items as gross_sum' => fn ($q) => $q->whereIn('type', PayrollItemType::earnings())], 'amount')
            ->withSum(['items as deduction_sum' => fn ($q) => $q->whereIn('type', PayrollItemType::deductions())], 'amount');
    }

    /** Satu-satunya tempat bruto/potongan/neto dihitung. */
    public function totals(): array
    {
        if (array_key_exists('gross_sum', $this->attributes)) {
            $gross = (int) $this->gross_sum;
            $deductions = (int) $this->deduction_sum;
        } else {
            $items = $this->relationLoaded('items') ? $this->items : $this->items()->get();

            $gross = (int) $items->reject(fn ($item) => $item->type->isDeduction())->sum('amount');
            $deductions = (int) $items->filter(fn ($item) => $item->type->isDeduction())->sum('amount');
        }

        return ['gross' => $gross, 'deductions' => $deductions, 'net' => $gross - $deductions];
    }
}
