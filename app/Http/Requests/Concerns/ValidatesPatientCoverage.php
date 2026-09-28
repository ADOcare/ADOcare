<?php

namespace App\Http\Requests\Concerns;

use App\Enums\PatientCoverageRegime;
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
            'coverage.regime' => [
                'required_with:coverage',
                Rule::in(PatientCoverageRegime::values()),
            ],
            'coverage.category' => ['sometimes', 'nullable', Rule::enum(PatientCoverageCategory::class)],
            'coverage.identification_method' => [
                'sometimes',
                'nullable',
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
            'coverage.other_subtype' => ['nullable', 'string', 'max:100'],
            'coverage.legal_basis' => ['nullable', 'string', 'max:255'],
            'coverage.entitlement_confirmed' => ['sometimes', 'boolean'],
            'coverage.document_registered' => ['sometimes', 'boolean'],
            'coverage.entitlement_document_type' => ['nullable', 'string', 'max:50'],
            'coverage.entitlement_document_number' => ['nullable', 'string', 'max:100'],
            'coverage.valid_from' => ['nullable', 'date'],
            'coverage.valid_to' => ['nullable', 'date', 'after_or_equal:coverage.valid_from'],
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

            $regime = $coverage['regime'] ?? null;
            $category = $coverage['category'] ?? null;
            $method = $coverage['identification_method'] ?? null;

            if (
                $regime !== PatientCoverageRegime::UNCLASSIFIED->value
                && blank($coverage['insurance_company_id'] ?? null)
            ) {
                $validator->errors()->add('coverage.insurance_company_id', 'Pre vykazovanie je povinná slovenská zdravotná poisťovňa.');
            }

            if ($regime === PatientCoverageRegime::EU->value) {
                if (blank($coverage['member_state_code'] ?? null)) {
                    $validator->errors()->add(
                        'coverage.member_state_code',
                        'Pre poistenca EÚ je povinný príslušný štát poistenia.'
                    );
                }

                if (blank($coverage['foreign_insured_id'] ?? null)) {
                    $validator->errors()->add(
                        'coverage.foreign_insured_id',
                        'Pre poistenca EÚ je povinné zahraničné identifikačné číslo.'
                    );
                }
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

            if ($category === PatientCoverageCategory::OTHER->value && blank($coverage['other_subtype'] ?? null)) {
                $validator->errors()->add('coverage.other_subtype', 'Kategória Iné vyžaduje konkrétny podtyp.');
            }

            if (
                $category === PatientCoverageCategory::DOMESTIC->value
                && ($regime !== PatientCoverageRegime::DOMESTIC->value
                    || $method !== PatientIdentificationMethod::SLOVAK_IDENTIFIER->value)
            ) {
                $validator->errors()->add('coverage.regime', 'Tuzemská kategória vyžaduje tuzemský režim a slovenský identifikátor.');
            }

            if (
                $category === PatientCoverageCategory::EU->value
                && ($regime !== PatientCoverageRegime::EU->value
                    || $method !== PatientIdentificationMethod::FOREIGN_TRIAD->value)
            ) {
                $validator->errors()->add('coverage.regime', 'Kategória EÚ vyžaduje zahraničný režim a identifikačnú trojicu.');
            }

            if (
                $category === PatientCoverageCategory::HOMELESS->value
                && ($regime !== PatientCoverageRegime::SPECIAL->value
                    || ($coverage['special_category'] ?? null) !== PatientSpecialCoverageCategory::HOMELESS->value)
            ) {
                $validator->errors()->add('coverage.special_category', 'Bezdomovec musí mať výslovne potvrdený osobitný režim podľa § 9 ods. 4.');
            }

            if (
                in_array($category, [
                    PatientCoverageCategory::NON_EU->value,
                    PatientCoverageCategory::HOMELESS->value,
                    PatientCoverageCategory::OTHER->value,
                ], true)
                && blank($coverage['legal_basis'] ?? null)
            ) {
                $validator->errors()->add('coverage.legal_basis', 'Pre túto kategóriu je povinný právny základ nároku.');
            }

            if (
                $regime === PatientCoverageRegime::SPECIAL->value
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
