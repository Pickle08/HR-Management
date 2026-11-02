<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\PayrollItemRules;
use Illuminate\Foundation\Http\FormRequest;

class SavePayrollItemsRequest extends FormRequest
{
    use PayrollItemRules;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('payroll'));
    }

    public function rules(): array
    {
        return $this->itemRules(['present', 'array', 'max:30']);
    }
}
