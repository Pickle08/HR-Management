<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\PayrollItemRules;
use App\Models\Payroll;
use Illuminate\Foundation\Http\FormRequest;

class StorePayrollRequest extends FormRequest
{
    use PayrollItemRules;

    public function authorize(): bool
    {
        return $this->user()->can('create', Payroll::class);
    }

    public function rules(): array
    {
        return array_merge([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'year' => ['required', 'integer', 'between:2000,2100'],
            'month' => ['required', 'integer', 'between:1,12'],
        ], $this->itemRules(['sometimes', 'array', 'max:30']));
    }
}
