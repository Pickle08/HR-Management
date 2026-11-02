<?php

namespace App\Http\Requests\Concerns;

use App\Enums\PayrollItemType;
use Illuminate\Validation\Rule;

trait PayrollItemRules
{
    /**
     * Cuma bentuk datanya. Aturan yang butuh konteks (tanda nominal, jenis yang
     * boleh diubah per role, gaji pokok maksimal satu) ada di PayrollService.
     */
    protected function itemRules(array $itemsRules): array
    {
        return [
            'items' => $itemsRules,
            'items.*.type' => ['required', Rule::enum(PayrollItemType::class)],
            'items.*.label' => ['nullable', 'string', 'max:100'],
            'items.*.amount' => ['required', 'integer', 'between:-10000000000,10000000000'],
        ];
    }
}
