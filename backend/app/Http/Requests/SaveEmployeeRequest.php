<?php

namespace App\Http\Requests;

use App\Rules\ActiveManager;
use App\Enums\EmploymentStatus;
use App\Models\Employee;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
                'nullable',
                'integer',
                'exists:employees,id',
                new ActiveManager,
                function (string $attribute, mixed $value, Closure $fail) use ($employee) {
                    if ($value === null || !$employee) {
                        return;
                    }

                    // Telusuri rantai atasan ke atas; kalau ketemu karyawan ini = siklus.
                    $seen = [];
                    $current = (int) $value;

                    while ($current !== 0 && !in_array($current, $seen, true)) {
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
                'required',
                'string',
                'max:20',
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

            // Wilayah: kosong semua, atau terisi semua dan satu rantai.
            'province_code' => ['nullable', 'required_with:regency_code,district_code,village_code', 'string', 'exists:provinces,code'],
            'regency_code' => ['nullable', 'required_with:province_code,district_code,village_code', 'string', 'exists:regencies,code'],
            'district_code' => ['nullable', 'required_with:province_code,regency_code,village_code', 'string', 'exists:districts,code'],
            'village_code' => ['nullable', 'required_with:province_code,regency_code,district_code', 'string', 'exists:villages,code'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty() || ! $this->filled('village_code')) {
                    return;
                }

                $chained = DB::table('villages')
                    ->join('districts', 'districts.code', '=', 'villages.district_code')
                    ->join('regencies', 'regencies.code', '=', 'districts.regency_code')
                    ->where('villages.code', $this->input('village_code'))
                    ->where('districts.code', $this->input('district_code'))
                    ->where('regencies.code', $this->input('regency_code'))
                    ->where('regencies.province_code', $this->input('province_code'))
                    ->exists();

                if (! $chained) {
                    $validator->errors()->add('village_code', 'Pilihan wilayah tidak berurutan: desa harus berada di kecamatan, kabupaten/kota, dan provinsi yang dipilih.');
                }
            },
        ];
    }
}
