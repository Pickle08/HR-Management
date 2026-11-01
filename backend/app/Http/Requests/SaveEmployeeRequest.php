<?php

namespace App\Http\Requests;

use App\Enums\EmploymentStatus;
use App\Models\Employee;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $employee = $this->route('employee');

        return $employee
            ? $this->user()->can('update', $employee)
            : $this->user()->can('create', Employee::class);
    }

    public function rules(): array
    {
        $employee = $this->route('employee');

        return [
            'manager_id' => [
                'nullable', 'integer', 'exists:employees,id',
                function (string $attribute, mixed $value, Closure $fail) use ($employee) {
                    if ($value === null || ! $employee) {
                        return;
                    }

                    // Telusuri rantai atasan ke atas; kalau ketemu karyawan ini = siklus.
                    $seen = [];
                    $current = (int) $value;

                    while ($current !== 0 && ! in_array($current, $seen, true)) {
                        if ($current === $employee->id) {
                            $fail('Atasan tidak boleh membentuk siklus.');

                            return;
                        }

                        $seen[] = $current;
                        $current = (int) Employee::whereKey($current)->value('manager_id');
                    }
                },
            ],
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'position_id' => ['required', 'integer', 'exists:positions,id'],
            'employee_number' => [
                'required', 'string', 'max:20',
                Rule::unique('employees', 'employee_number')->ignore($employee?->id),
            ],
            'full_name' => ['required', 'string', 'max:150'],
            'nik' => ['nullable', 'digits:16'],
            'phone' => ['nullable', 'string', 'max:20'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'hire_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:hire_date'],
            'employment_status' => ['required', Rule::enum(EmploymentStatus::class)],
            'address' => ['nullable', 'string', 'max:500'],
        ];
    }
}
