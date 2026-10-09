<?php

namespace App\Http\Requests\Concerns;

use App\Enums\PatientCoverageCategory;
use App\Enums\PatientIdentificationMethod;
use App\Enums\PatientSpecialCoverageCategory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait ValidatesPatientCoverage
{
    protected function patientCoverageRules(bool $required = false): array
    {
        return [
            'coverage' => [$required ? 'required' : 'sometimes', 'array'],
            'coverage.id' => ['nullable', 'integer'],
            'coverage.category' => ['required_with:coverage', Rule::enum(PatientCoverageCategory::class)],
            'coverage.identification_method' => [
                'required_with:coverage',
                Rule::enum(PatientIdentificationMethod::class),
            ],
            'coverage.insurance_company_id' => [
                'nullable',
                'integer',
                'exists:insurance_companies,id',
            ],
            'coverage.member_state_code' => ['nullable', 'string', 'regex:/^[A-Za-z]{2,3}$/'],
            'coverage.foreign_insured_id' => ['nullable', 'string', 'max:20'],
            'coverage.special_category' => [
                'nullable',
                Rule::enum(PatientSpecialCoverageCategory::class),
            ],
            'coverage.is_verified' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $coverage = $this->input('coverage');

            if (!is_array($coverage)) {
                return;
            }

            $category = $coverage['category'] ?? null;
            $method = $coverage['identification_method'] ?? null;

            if (blank($coverage['insurance_company_id'] ?? null)) {
                $validator->errors()->add('coverage.insurance_company_id', 'Pre vykazovanie je povinná slovenská zdravotná poisťovňa.');
            }

            $expectedMethod = match ($category) {
                PatientCoverageCategory::DOMESTIC->value => PatientIdentificationMethod::SLOVAK_IDENTIFIER->value,
                PatientCoverageCategory::EU->value => PatientIdentificationMethod::FOREIGN_TRIAD->value,
                default => $method,
            };

            if ($method !== $expectedMethod) {
                $validator->errors()->add('coverage.identification_method', 'Zvolený spôsob identifikácie nezodpovedá typu poistenia.');
            }

            if ($method === PatientIdentificationMethod::FOREIGN_TRIAD->value) {
                if (blank($coverage['member_state_code'] ?? null)) {
                    $validator->errors()->add('coverage.member_state_code', 'Pri zahraničnej identifikácii je povinný štát poistenia.');
                }

                if (blank($coverage['foreign_insured_id'] ?? null)) {
                    $validator->errors()->add('coverage.foreign_insured_id', 'Pri zahraničnej identifikácii je povinné identifikačné číslo poistenca.');
                }

                if (! in_array($this->input('sex'), ['M', 'F'], true)) {
                    $validator->errors()->add('sex', 'Pri zahraničnej identifikácii je povinné pohlavie M alebo F.');
                }
            }

            if (
                $method === PatientIdentificationMethod::SLOVAK_IDENTIFIER->value
                && blank($this->input('personal_number'))
            ) {
                $validator->errors()->add('personal_number', 'Pri slovenskej identifikácii je povinné rodné číslo alebo pridelený BIČ.');
            }

            if (
                $category === PatientCoverageCategory::SPECIAL->value
                && blank($coverage['special_category'] ?? null)
            ) {
                $validator->errors()->add(
                    'coverage.special_category',
                    'Pre osobitnú skupinu je povinná kategória nároku.'
                );
            }
        });
    }
}
