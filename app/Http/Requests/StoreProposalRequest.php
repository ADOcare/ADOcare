<?php

namespace App\Http\Requests;

use App\Models\Patient;
use App\Support\ClinicalFrequency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $patientId = $this->input('patient_id');
        if (! $patientId) {
            return false;
        }

        $patient = Patient::find($patientId);
        if (! $patient) {
            return false;
        }

        // allow when user can create a patient in that branch (nurse in same branch or manager/admin)
        return $this->user()->isInBranch($patient->branch_id) || $this->user()->hasGlobalRole('manager');
    }

    public function rules(): array
    {
        return [
            'patient_id' => 'required|exists:patients,id',
            'branch_id' => 'required|exists:branches,id',
            'medical_diagnosis_ids' => 'nullable|array',
            'medical_diagnosis_ids.*' => 'nullable|exists:diagnoses,id',
            'nurse_diagnosis_ids' => 'nullable|array',
            'nurse_diagnosis_ids.*' => 'nullable|exists:nurse_diagnoses,id',
            'date' => 'required|date',
            'epicrisis_description' => 'required|string',
            'care_plan' => 'required|string',
            'patient_mobility' => 'nullable|array',
            'patient_mobility.*' => [Rule::in(['H', 'I', 'F'])],
            'expected_duration' => [
                'required',
                Rule::in(['one_month', 'three_months', 'six_months', 'over_six_months']),
            ],
            'procedures' => 'nullable|array',
            'procedures.*.procedure_id' => 'nullable|exists:procedures,id',
            'procedures.*.frequency' => [
                'nullable',
                Rule::in(ClinicalFrequency::acceptedValues()),
            ],
            'procedures.*.details' => 'nullable|array',
            'procedures.*.details.medicine_name' => 'nullable|string|max:255',
            'procedures.*.details.dose' => 'nullable|string|max:255',
            'procedures.*.details.solution_name' => 'nullable|string|max:255',
            'procedures.*.details.concentration' => 'nullable|string|max:255',
            'procedures.*.details.amount' => 'nullable|string|max:255',
            'procedures.*.details.administration_frequency' => 'nullable|string|max:255',
            'procedures.*.details.administration_duration' => 'nullable|string|max:255',
        ];
    }
}
