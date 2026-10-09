<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesPatientCoverage;
use Illuminate\Foundation\Http\FormRequest;

class BranchPatientStoreRequest extends FormRequest
{
    use ValidatesPatientCoverage;

    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return array_merge([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'title' => 'nullable|string|max:255',
            'personal_number' => 'nullable|string|max:255',
            'sex' => 'required|in:M,F',
            'contact' => 'nullable|email|max:255',
            'country_code_phone' => ['nullable', 'string', 'regex:/^\+[1-9]\d{0,3}$/'],
            'phone' => 'nullable|string|max:30',

            'doctor_id' => 'required|integer|exists:doctors,id',
            'insurance_company_id' => 'nullable|integer|exists:insurance_companies,id',

            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'zip' => 'nullable|string|max:50',

            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',

            'reference_date' => 'nullable|date',
            'dekurz_number' => 'nullable|integer|min:1',
        ], $this->patientCoverageRules(required: true));
    }
}
