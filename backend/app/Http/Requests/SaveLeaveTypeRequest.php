<?php

namespace App\Http\Requests;

use App\Models\LeaveType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveLeaveTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', LeaveType::class);
    }

    public function rules(): array
    {
        $type = $this->route('leave_type');

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('leave_types', 'name')->ignore($type?->id)],
            'annual_quota' => ['required', 'integer', 'between:0,365'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
