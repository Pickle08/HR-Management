<?php

namespace App\Http\Requests;

use App\Models\JobOpening;
use Illuminate\Foundation\Http\FormRequest;

class SaveJobOpeningRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', JobOpening::class);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'position_id' => ['required', 'integer', 'exists:positions,id'],
            'headcount' => ['required', 'integer', 'between:1,100'],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
