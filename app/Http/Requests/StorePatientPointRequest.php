<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePatientPointRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date'],
            'patient_personal_number' => ['nullable', 'string', 'max:255'],
            'patient_name' => ['required', 'string', 'max:255'],
            'patient_id' => ['nullable', 'integer'],

            'diagnosis_code' => ['required', 'string', 'max:255'],
            'diagnosis_id' => ['nullable', 'integer'],

            'procedure_code' => ['required', 'string', 'max:255'],
            'procedure_id' => ['nullable', 'integer'],

            'doctor_pzs' => ['nullable', 'string', 'max:255'],
            'doctor_zpr' => ['nullable', 'string', 'max:255'],
            'doctor_id' => ['nullable', 'integer'],

            'reference_date' => ['required', 'date'],
            'user_id' => ['nullable', 'integer'],
            'branch_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1'],
        ];
    }
}
