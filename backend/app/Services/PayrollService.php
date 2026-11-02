<?php

namespace App\Services;

use App\Enums\PayrollItemType;
use App\Enums\PayrollStatus;
use App\Models\Payroll;
use App\Models\PayrollItem;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayrollService
{
    public function create(User $actor, array $data): Payroll
    {
        if ($actor->employee?->id === (int) $data['employee_id']) {
            abort(403, 'Tidak boleh menyiapkan payroll sendiri.');
        }

        $period = (int) $data['year'] * 12 + (int) $data['month'];

        if ($period > now()->year * 12 + now()->month) {
            throw ValidationException::withMessages([
                'month' => ['Periode payroll tidak boleh di masa depan.'],
            ]);
        }

        return DB::transaction(function () use ($actor, $data) {
            $payroll = new Payroll();
            $payroll->forceFill([
                'employee_id' => $data['employee_id'],
                'year' => $data['year'],
                'month' => $data['month'],
                'revision_no' => 0,
                'status' => PayrollStatus::Draft,
                'prepared_by' => $actor->id,
            ]);

            try {
                $payroll->save();
            } catch (UniqueConstraintViolationException) {
                // Penjaga sebenarnya: unique index di database.
                throw ValidationException::withMessages([
                    'employee_id' => ['Payroll karyawan ini untuk bulan tersebut sudah ada.'],
                ]);
            }

            if (! empty($data['items'])) {
                $this->syncItems($payroll, $actor, $data['items']);
            }

            return $payroll;
        });
    }

    public function replaceItems(Payroll $payroll, User $actor, array $items): Payroll
    {
        return DB::transaction(function () use ($payroll, $actor, $items) {
            $locked = $this->lockDraft($payroll);

            $this->syncItems($locked, $actor, $items);

            return $locked;
        });
    }

    public function finalize(Payroll $payroll, User $actor): Payroll
    {
        return DB::transaction(function () use ($payroll, $actor) {
            $locked = $this->lockDraft($payroll);
            $items = $locked->items()->get();
            $locked->setRelation('items', $items);

            if ($locked->revision_no === 0) {
                $basicCount = $items->filter(fn ($i) => $i->type === PayrollItemType::BasicSalary)->count();

                if ($basicCount !== 1) {
                    throw ValidationException::withMessages([
                        'items' => ['Payroll harus punya tepat satu komponen gaji pokok.'],
                    ]);
                }

                if ($locked->totals()['net'] < 0) {
                    throw ValidationException::withMessages([
                        'items' => ['Gaji neto tidak boleh negatif. Periksa potongan.'],
                    ]);
                }
            } elseif ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => ['Koreksi harus punya minimal satu komponen.'],
                ]);
            }

            $locked->forceFill([
                'status' => PayrollStatus::Finalized,
                'finalized_by' => $actor->id,
                'finalized_at' => now(),
            ])->save();

            return $locked;
        });
    }

    public function createAdjustment(Payroll $original, User $actor, string $note, array $items): Payroll
    {
        return DB::transaction(function () use ($original, $actor, $note, $items) {
            $locked = Payroll::whereKey($original->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PayrollStatus::Finalized || $locked->revision_no !== 0) {
                abort(409, 'Koreksi hanya bisa dibuat untuk payroll asli yang sudah final.');
            }

            $siblings = Payroll::query()
                ->where('employee_id', $locked->employee_id)
                ->where('year', $locked->year)
                ->where('month', $locked->month);

            $hasOpenAdjustment = (clone $siblings)
                ->where('revision_no', '>', 0)
                ->where('status', PayrollStatus::Draft->value)
                ->exists();

            if ($hasOpenAdjustment) {
                throw ValidationException::withMessages([
                    'note' => ['Masih ada koreksi berstatus draft untuk periode ini. Selesaikan dulu.'],
                ]);
            }

            $revision = ((int) (clone $siblings)->max('revision_no')) + 1;

            $adjustment = new Payroll();
            $adjustment->forceFill([
                'employee_id' => $locked->employee_id,
                'year' => $locked->year,
                'month' => $locked->month,
                'revision_no' => $revision,
                'adjusts_payroll_id' => $locked->id,
                'status' => PayrollStatus::Draft,
                'note' => $note,
                'prepared_by' => $actor->id,
            ])->save();

            $this->syncItems($adjustment, $actor, $items);

            return $adjustment;
        });
    }

    public function delete(Payroll $payroll): void
    {
        DB::transaction(function () use ($payroll) {
            $this->lockDraft($payroll)->delete();
        });
    }

    private function lockDraft(Payroll $payroll): Payroll
    {
        $locked = Payroll::whereKey($payroll->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== PayrollStatus::Draft) {
            abort(409, 'Payroll sudah final dan terkunci.');
        }

        return $locked;
    }

    /**
     * Mengganti komponen dalam "jangkauan" role pelaku: HR = semua jenis,
     * Finance = hanya bpjs dan pph21 (komponen lain dibiarkan).
     */
    private function syncItems(Payroll $payroll, User $actor, array $items): void
    {
        $editable = PayrollItemType::editableBy($actor->role);
        $isAdjustment = $payroll->revision_no > 0;
        $errors = [];
        $basicCount = 0;

        foreach (array_values($items) as $i => $item) {
            $type = PayrollItemType::from($item['type']);
            $amount = (int) $item['amount'];

            if (! in_array($type, $editable, true)) {
                $errors["items.$i.type"] = ['Peran Anda tidak boleh mengubah jenis komponen ini.'];
            }

            if ($isAdjustment ? $amount === 0 : $amount < 0) {
                $errors["items.$i.amount"] = [
                    $isAdjustment ? 'Nominal koreksi tidak boleh nol.' : 'Nominal tidak boleh negatif.',
                ];
            }

            if ($type === PayrollItemType::BasicSalary && ++$basicCount > 1) {
                $errors["items.$i.type"] = ['Gaji pokok hanya boleh satu.'];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $scope = array_map(fn (PayrollItemType $type) => $type->value, $editable);

        $existing = $payroll->items()->whereIn('type', $scope)->orderBy('id')->get();
        $old = $this->snapshot($existing);
        $existing->each->delete();

        foreach ($items as $item) {
            $payroll->items()->create([
                'type' => $item['type'],
                'label' => $item['label'] ?? null,
                'amount' => (int) $item['amount'],
            ]);
        }

        $new = $this->snapshot($payroll->items()->whereIn('type', $scope)->orderBy('id')->get());

        if ($old !== $new) {
            Audit::record('payroll.items_replaced', $payroll, ['items' => $old], ['items' => $new]);
        }
    }

    private function snapshot(Collection $items): array
    {
        return $items->map(fn (PayrollItem $item) => [
            'type' => $item->type->value,
            'label' => $item->label,
            'amount' => $item->amount,
        ])->values()->all();
    }
}
