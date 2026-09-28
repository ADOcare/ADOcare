<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePatientPointRequest extends FormRequest
{
    public function authorize(): bool
    {
        $point = $this->route('patient_point');
        if (!$point) {
            return false;
        }

        return $this->user()?->can('update', $point) ?? false;
    }

    public function rules(): array
    {
        return [
            'date' => ['sometimes', 'required', 'date'],
            'patient_personal_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'patient_name' => ['sometimes', 'required', 'string', 'max:255'],
            'patient_id' => ['sometimes', 'required', 'integer'],

            'diagnosis_code' => ['sometimes', 'required', 'string', 'max:255'],
            'diagnosis_id' => ['sometimes', 'required', 'integer'],

            'procedure_code' => ['sometimes', 'required', 'string', 'max:255'],
            'procedure_id' => ['sometimes', 'required', 'integer'],

            'doctor_pzs' => ['sometimes', 'nullable', 'string', 'max:255'],
            'doctor_zpr' => ['sometimes', 'nullable', 'string', 'max:255'],
            'doctor_id' => ['sometimes', 'nullable', 'integer'],

            'reference_date' => ['sometimes', 'required', 'date'],
            'user_id' => ['sometimes', 'required', 'integer'],
            'branch_id' => ['sometimes', 'required', 'integer'],
            'quantity' => ['sometimes', 'required', 'integer', 'min:1'],
        ];
    }
}
