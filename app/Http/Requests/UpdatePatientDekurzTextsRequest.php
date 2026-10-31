<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Validate the patient's reusable Dekurz texts, including unfinished empty sections. */
class UpdatePatientDekurzTextsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('patient')) ?? false;
    }

    public function rules(): array
    {
        return [
            'texts' => ['present', 'array', 'max:100'],
            'texts.*' => ['present', 'nullable', 'string', 'max:20000'],
        ];
    }

    public function messages(): array
    {
        return [
            'texts.max' => 'Dekurz môže mať najviac 100 textových sekcií.',
            'texts.*.max' => 'Text sekcie môže mať najviac 20 000 znakov.',
        ];
    }
}
