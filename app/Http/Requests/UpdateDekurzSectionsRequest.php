<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Validate free-text sections while allowing incomplete work to be saved as a draft. */
class UpdateDekurzSectionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'sections' => ['required', 'array', 'min:1', 'max:100'],
            'sections.*.text' => ['present', 'nullable', 'string', 'max:20000'],
            'sections.*.dates' => ['present', 'array', 'max:31'],
            'sections.*.dates.*' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
