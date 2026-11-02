<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', User::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'role' => ['required', Rule::enum(Role::class)],
            // Semua peran selain Super Admin wajib tertaut ke data karyawan.
            'employee_id' => ['nullable', 'required_unless:role,super_admin', 'integer', 'exists:employees,id'],
            // Nama diambil dari karyawan; hanya Super Admin tanpa data karyawan yang perlu mengisi.
            'name' => ['nullable', 'required_without:employee_id', 'string', 'max:150'],
        ];
    }
}
