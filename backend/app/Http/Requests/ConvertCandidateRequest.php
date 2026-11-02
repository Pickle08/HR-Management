<?php

namespace App\Http\Requests;

use App\Enums\EmploymentStatus;
use App\Models\Candidate;
use App\Rules\ActiveManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConvertCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', Candidate::class);
    }

    // Nama dan telepon diambil dari kandidat. Departemen dan jabatan default ke lowongan.
    public function rules(): array
    {
        return [
            'employee_number' => ['required', 'string', 'max:20', Rule::unique('employees', 'employee_number')],
            'department_id' => ['sometimes', 'integer', 'exists:departments,id'],
            'position_id' => ['sometimes', 'integer', 'exists:positions,id'],
            'manager_id' => ['nullable', 'integer', 'exists:employees,id', new ActiveManager],
            'nik' => ['nullable', 'digits:16'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'hire_date' => ['required', 'date'],
            'employment_status' => ['required', Rule::enum(EmploymentStatus::class)],
            'address' => ['nullable', 'string', 'max:500'],
        ];
    }
}
