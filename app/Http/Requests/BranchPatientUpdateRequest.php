<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesPatientCoverage;
use Illuminate\Foundation\Http\FormRequest;

class BranchPatientUpdateRequest extends FormRequest
{
    use ValidatesPatientCoverage;

    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return array_merge([
            'first_name' => 'sometimes|required|string|max:255',
            'last_name' => 'sometimes|required|string|max:255',
            'title' => 'nullable|string|max:255',
            'personal_number' => 'nullable|string|max:255',
            'sex' => 'nullable|in:M,F',
            'contact' => 'nullable|string|max:255',

            'doctor_id' => 'nullable|integer|exists:doctors,id',
            'insurance_company_id' => 'nullable|integer|exists:insurance_companies,id',

            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'zip' => 'nullable|string|max:50',

            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',

            'reference_date' => 'nullable|date',
        ], $this->patientCoverageRules());
    }
}
