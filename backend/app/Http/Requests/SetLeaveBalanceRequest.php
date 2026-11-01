<?php

namespace App\Http\Requests;

use App\Models\LeaveBalance;
use Illuminate\Foundation\Http\FormRequest;

class SetLeaveBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', LeaveBalance::class);
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'leave_type_id' => ['required', 'integer', 'exists:leave_types,id'],
            'year' => ['required', 'integer', 'between:2000,2100'],
            'entitled_days' => ['required', 'integer', 'between:0,365'],
        ];
    }
}
