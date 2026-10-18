<?php

namespace App\Http\Requests;

use App\Support\ClinicalFrequency;
use Illuminate\Validation\Rule;

class ValidateProposalRulesRequest extends StoreProposalRequest
{
    public function rules(): array
    {
        return [
            'patient_id' => ['required', 'integer', 'exists:patients,id'],
            'date' => ['required', 'date'],
            'medical_diagnosis_ids' => ['nullable', 'array'],
            'medical_diagnosis_ids.*' => ['integer', 'exists:diagnoses,id'],
            'nurse_diagnosis_ids' => ['nullable', 'array'],
            'nurse_diagnosis_ids.*' => ['integer', 'exists:nurse_diagnoses,id'],
            'patient_mobility' => ['nullable', 'array'],
            'patient_mobility.*' => [Rule::in(['H', 'I', 'F'])],
            'expected_duration' => [
                'nullable',
                Rule::in(['one_month', 'three_months', 'six_months', 'over_six_months']),
            ],
            'procedures' => ['nullable', 'array'],
            'procedures.*.procedure_id' => ['required', 'integer', 'exists:procedures,id'],
            'procedures.*.frequency' => [
                'nullable',
                Rule::in(ClinicalFrequency::acceptedValues()),
            ],
            'procedures.*.details' => ['nullable', 'array'],
            'procedures.*.details.*' => ['nullable', 'string', 'max:255'],
        ];
    }
}
