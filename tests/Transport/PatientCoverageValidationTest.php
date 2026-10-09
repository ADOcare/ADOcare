<?php

namespace Tests\Transport;

use App\Http\Requests\Concerns\ValidatesPatientCoverage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\DatabasePresenceVerifier;

class PatientCoverageValidationTest extends TransportTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->database();

        Schema::create('insurance_companies', function ($table) {
            $table->id();
        });
        DB::table('insurance_companies')->insert(['id' => 1]);
        Validator::getFacadeRoot()->setPresenceVerifier(
            new DatabasePresenceVerifier(DB::getFacadeRoot()),
        );
    }

    /** @dataProvider validCoveragePayloads */
    public function test_simplified_coverage_payload_is_valid(array $payload): void
    {
        $validator = $this->validator($payload);

        self::assertTrue($validator->passes(), json_encode($validator->errors()->toArray()));
    }

    public function test_foreign_identification_requires_patient_sex(): void
    {
        $validator = $this->validator([
            'personal_number' => null,
            'sex' => null,
            'coverage' => [
                'category' => 'eu',
                'insurance_company_id' => 1,
                'identification_method' => 'foreign_triad',
                'member_state_code' => 'CZ',
                'foreign_insured_id' => 'CZ001',
                'special_category' => null,
            ],
        ]);

        self::assertTrue($validator->fails());
        self::assertArrayHasKey('sex', $validator->errors()->toArray());
    }

    public static function validCoveragePayloads(): array
    {
        return [
            'domestic' => [[
                'personal_number' => '9001011234',
                'sex' => 'M',
                'coverage' => [
                    'category' => 'domestic',
                    'insurance_company_id' => 1,
                    'identification_method' => 'slovak_identifier',
                    'member_state_code' => null,
                    'foreign_insured_id' => null,
                    'special_category' => null,
                ],
            ]],
            'eu' => [[
                'personal_number' => null,
                'sex' => 'F',
                'coverage' => [
                    'category' => 'eu',
                    'insurance_company_id' => 1,
                    'identification_method' => 'foreign_triad',
                    'member_state_code' => 'CZ',
                    'foreign_insured_id' => 'CZ001',
                    'special_category' => null,
                ],
            ]],
            'special with Slovak identifier' => [[
                'personal_number' => 'BIC12345',
                'sex' => 'M',
                'coverage' => [
                    'category' => 'special',
                    'insurance_company_id' => 1,
                    'identification_method' => 'slovak_identifier',
                    'member_state_code' => null,
                    'foreign_insured_id' => null,
                    'special_category' => 'homeless',
                ],
            ]],
            'special with foreign triad' => [[
                'personal_number' => null,
                'sex' => 'F',
                'coverage' => [
                    'category' => 'special',
                    'insurance_company_id' => 1,
                    'identification_method' => 'foreign_triad',
                    'member_state_code' => 'UA',
                    'foreign_insured_id' => 'UA001',
                    'special_category' => 'non_eu_foreigner',
                ],
            ]],
        ];
    }

    private function validator(array $payload): \Illuminate\Validation\Validator
    {
        $request = new class extends Request {
            use ValidatesPatientCoverage;

            public function rules(): array
            {
                return $this->patientCoverageRules(required: true);
            }
        };
        $request->initialize([], $payload);
        $request->setMethod('POST');

        $validator = Validator::make($payload, array_merge([
            'personal_number' => ['nullable', 'string'],
            'sex' => ['nullable', 'in:M,F'],
        ], $request->rules()));
        $request->withValidator($validator);

        return $validator;
    }
}
