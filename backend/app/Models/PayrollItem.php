<?php

namespace App\Models;

use App\Enums\PayrollItemType;
use App\Enums\PayrollStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PayrollItem extends Model
{
    // Tidak pakai Auditable: perubahan item dicatat sebagai satu event
    // "payroll.items_replaced" (sebelum dan sesudah) oleh PayrollService.

    protected $fillable = ['type', 'label', 'amount'];

    protected function casts(): array
    {
        return [
            'type' => PayrollItemType::class,
            'amount' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        $guard = function (PayrollItem $item) {
            $finalized = Payroll::query()
                ->whereKey($item->payroll_id)
                ->where('status', PayrollStatus::Finalized->value)
                ->exists();

            if ($finalized) {
                throw new LogicException('Komponen payroll final tidak boleh diubah.');
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class);
    }
}
