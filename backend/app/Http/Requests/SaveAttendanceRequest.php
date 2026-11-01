<?php

namespace App\Http\Requests;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $attendance = $this->route('attendance');

        return $attendance
            ? $this->user()->can('update', $attendance)
            : $this->user()->can('create', Attendance::class);
    }

    public function rules(): array
    {
        $rules = [
            'status' => ['required', Rule::enum(AttendanceStatus::class)],
            'check_in' => ['nullable', 'date_format:H:i', 'prohibited_unless:status,hadir'],
            'check_out' => [
                'nullable', 'date_format:H:i', 'prohibited_unless:status,hadir',
                Rule::when($this->filled('check_in'), ['after:check_in']),
            ],
            'note' => ['nullable', 'string', 'max:500'],
        ];

        // Karyawan dan tanggal cuma ditentukan saat membuat, tidak bisa dipindah lewat koreksi.
        if (! $this->route('attendance')) {
            $rules['employee_id'] = [
                'required', 'integer',
                Rule::exists('employees', 'id')->where('is_active', true),
            ];
            $rules['date'] = ['required', 'date_format:Y-m-d', 'before_or_equal:today'];
        }

        return $rules;
    }
}
