<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesPatientCoverage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PatientUpdateRequest extends FormRequest
{
    use ValidatesPatientCoverage;

    public function authorize()
    {
        return $this->user() !== null;
    }

    public function rules()
    {
        return array_merge([

            'first_name' => 'sometimes|required|string|max:255',
            'last_name' => 'sometimes|required|string|max:255',
            'title' => 'nullable|string|max:255',
            'personal_number' => 'nullable|string|max:255',
            'sex' => 'required|in:M,F',
            'contact' => 'nullable|email|max:255',
            'country_code_phone' => ['nullable', 'string', 'regex:/^\+[1-9]\d{0,3}$/'],
            'phone' => 'nullable|string|max:30',

            'branch_id' => 'sometimes|integer|exists:branches,id',
            'nurse_id' => 'nullable|integer|exists:users,id',
            'doctor_id' => 'required|integer|exists:doctors,id',
            'insurance_company_id' => 'nullable|integer|exists:insurance_companies,id',

            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'zip' => 'nullable|string|max:50',
            'country_id' => 'nullable|integer|exists:countries,id',

            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',

            'reference_date' => 'nullable|date',
            'death_date' => 'nullable|date',
            'dekurz_number' => 'nullable|integer|min:1',
            'effective_date' => 'nullable|date_format:Y-m-d',
            'effective_dates' => 'nullable|array',
            'effective_dates.name' => 'nullable|date_format:Y-m-d',
            'effective_dates.address' => 'nullable|date_format:Y-m-d',
            'effective_dates.doctor' => 'nullable|date_format:Y-m-d',
            'effective_dates.nurse' => 'nullable|date_format:Y-m-d',
            'effective_dates.branch' => 'nullable|date_format:Y-m-d',
            'effective_dates.identity' => 'nullable|date_format:Y-m-d',
            'effective_dates.contact' => 'nullable|date_format:Y-m-d',
            'effective_dates.insurance' => 'nullable|date_format:Y-m-d',
            'change_type' => 'nullable|in:change,correction',
            'change_reason' => 'nullable|string|max:1000',
        ], $this->patientCoverageRules());
    }

    public function withValidator(Validator $validator): void
    {
        $this->validatePatientCoverage($validator);

        $validator->after(function (Validator $validator): void {
            $patient = $this->route('patient');
            if (! $patient instanceof \App\Models\Patient) {
                return;
            }

            $historicalFields = [
                'first_name', 'last_name', 'title',
                'address', 'city', 'zip', 'country_id', 'latitude', 'longitude',
                'doctor_id', 'nurse_id', 'branch_id',
                'personal_number', 'sex',
                'contact', 'country_code_phone', 'phone',
            ];
            $changed = false;

            foreach ($historicalFields as $field) {
                if (! $this->exists($field)) {
                    continue;
                }

                if ((string) ($this->input($field) ?? '') !== (string) ($patient->{$field} ?? '')) {
                    $changed = true;
                    break;
                }
            }

            if (
                $this->exists('insurance_company_id')
                && (string) ($this->input('insurance_company_id') ?? '')
                    !== (string) ($patient->insurance_company_id ?? '')
            ) {
                $changed = true;
            }

            $coverage = $this->input('coverage');
            if (is_array($coverage)) {
                $current = $patient->currentCoverage()->first();
                foreach ([
                    'insurance_company_id',
                    'category',
                    'identification_method',
                    'member_state_code',
                    'foreign_insured_id',
                    'special_category',
                ] as $field) {
                    if (! array_key_exists($field, $coverage)) {
                        continue;
                    }

                    $currentValue = $current?->{$field};
                    $currentValue = $currentValue instanceof \BackedEnum ? $currentValue->value : $currentValue;
                    if ((string) ($coverage[$field] ?? '') !== (string) ($currentValue ?? '')) {
                        $changed = true;
                        break;
                    }
                }
            }

            if (
                $changed
                && blank($this->input('effective_date'))
                && blank($this->input('effective_dates'))
            ) {
                $validator->errors()->add('effective_date', 'Pri zmene historických údajov je povinné zadať dátum platnosti.');
            }

            if (
                $changed
                && $this->input('change_type') === 'correction'
                && blank($this->input('change_reason'))
            ) {
                $validator->errors()->add('change_reason', 'Pri oprave nesprávnych údajov je dôvod povinný.');
            }
        });
    }
}
