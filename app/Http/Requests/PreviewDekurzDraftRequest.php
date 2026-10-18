<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PreviewDekurzDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'patient_id' => ['required', 'integer', 'exists:patients,id'],
            'month' => ['required', 'date_format:Y-m'],
            'dekurz_number' => ['nullable', 'integer', 'min:1'],
            'days' => ['nullable', 'array', 'max:31'],
            'days.*.date' => ['required_with:days', 'date_format:Y-m-d'],
            'days.*.general_note' => ['nullable', 'string', 'max:20000'],
            'days.*.entries' => ['required_with:days', 'array'],
            'days.*.entries.*.text' => ['nullable', 'string', 'max:20000'],
        ];
    }
}
