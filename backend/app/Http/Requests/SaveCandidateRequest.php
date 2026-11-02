<?php

namespace App\Http\Requests;

use App\Models\Candidate;
use Illuminate\Foundation\Http\FormRequest;

class SaveCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', Candidate::class);
    }

    public function rules(): array
    {
        $rules = [
            'full_name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:20'],
        ];

        // Lowongan cuma ditentukan saat melamar, tidak bisa dipindah lewat edit.
        if (! $this->route('candidate')) {
            $rules['job_opening_id'] = ['required', 'integer', 'exists:job_openings,id'];
        }

        return $rules;
    }
}
