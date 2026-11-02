<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\PayrollItemRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreAdjustmentRequest extends FormRequest
{
    use PayrollItemRules;

    public function authorize(): bool
    {
        return $this->user()->can('adjust', $this->route('payroll'));
    }

    public function rules(): array
    {
        return array_merge(
            ['note' => ['required', 'string', 'max:500']],
            $this->itemRules(['required', 'array', 'min:1', 'max:30']),
        );
    }
}
