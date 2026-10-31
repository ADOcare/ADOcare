<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Validates shared properties without permitting execution-date changes. */
class BulkUpdatePatientPointRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:1000'],
            'ids.*' => ['required', 'integer', 'distinct', 'exists:patient_points,id'],
            'diagnosis_id' => ['required', 'integer', 'exists:diagnoses,id'],
            'procedure_id' => ['required', 'integer', 'exists:procedures,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'reference_date' => ['required', 'date_format:Y-m-d'],
            'date' => ['prohibited'],
        ];
    }
}
