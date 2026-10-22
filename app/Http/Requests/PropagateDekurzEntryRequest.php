<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PropagateDekurzEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'text' => ['required', 'string', 'max:20000'],
            'mode' => ['required', Rule::in(['current', 'following', 'selected'])],
            'target_entry_ids' => ['nullable', 'array'],
            'target_entry_ids.*' => ['integer', 'distinct', 'exists:dekurz_entries,id'],
            'target_dates' => ['nullable', 'array'],
            'target_dates.*' => ['date_format:Y-m-d', 'distinct'],
            'save_as_patient_template' => ['sometimes', 'boolean'],
        ];
    }
}
