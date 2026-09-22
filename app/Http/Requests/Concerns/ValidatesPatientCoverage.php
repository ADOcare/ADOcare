<?php

namespace App\Http\Requests\Concerns;

use App\Enums\PatientCoverageRegime;
use App\Enums\PatientSpecialCoverageCategory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait ValidatesPatientCoverage
{
    protected function patientCoverageRules(bool $required = false): array
    {
        $regimes = $required
            ? [
                PatientCoverageRegime::DOMESTIC->value,
                PatientCoverageRegime::EU->value,
                PatientCoverageRegime::SPECIAL->value,
            ]
            : PatientCoverageRegime::values();

        return [
            'coverage' => [$required ? 'required' : 'sometimes', 'array'],
            'coverage.id' => ['nullable', 'integer'],
            'coverage.regime' => [
                'required_with:coverage',
                Rule::in($regimes),
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
