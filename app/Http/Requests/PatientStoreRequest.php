<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesPatientCoverage;
use Illuminate\Foundation\Http\FormRequest;

class PatientStoreRequest extends FormRequest
{
    use ValidatesPatientCoverage;

    public function authorize()
    {
        return $this->user() !== null;
    }

    public function rules()
    {
        return array_merge([
            'branch_id' => 'required|integer|exists:branches,id',

            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'title' => 'nullable|string|max:255',
            'personal_number' => 'nullable|string|max:255',
            'sex' => 'nullable|in:M,F',
            'contact' => 'nullable|string|max:255',

            'doctor_id' => 'nullable|integer|exists:doctors,id',
            'insurance_company_id' => 'nullable|integer|exists:insurance_companies,id',
            'nurse_id' => 'nullable|integer|exists:users,id',

            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'zip' => 'nullable|string|max:50',

            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',

            'reference_date' => 'nullable|date',
            'death_date' => 'nullable|date',
        ], $this->patientCoverageRules(required: true));
    }
}
