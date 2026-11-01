<?php

namespace App\Http\Requests;

use App\Models\LeaveRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', LeaveRequest::class);
    }

    // employee_id, status, days, year sengaja tidak ada di sini: semuanya ditentukan server.
    public function rules(): array
    {
        return [
            'leave_type_id' => [
                'required', 'integer',
                Rule::exists('leave_types', 'id')->where('is_active', true),
            ],
            'start_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now()->toDateString()],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
