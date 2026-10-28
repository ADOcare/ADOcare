<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\Procedure;
use App\Services\AdosProcedureRuleResolver;
use App\Services\AdosProposalValidationService;
use App\Support\ClinicalFrequency;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

class AdosProposalValidationServiceTest extends TestCase
{
    private Patient $patient;

    private AdosProposalValidationService $service;

    /** @var array<string, Procedure> */
    private array $procedures = [];

    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container;
        Container::setInstance($app);
        $app->instance('config', new Repository([
            'database.default' => 'testing',
            'ados_proposal_rules' => require dirname(__DIR__, 2).'/config/ados_proposal_rules.php',
            'clinical_frequencies' => require dirname(__DIR__, 2).'/config/clinical_frequencies.php',
        ]));

        $database = new Manager($app);
        $database->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ], 'testing');
        $database->setAsGlobal();
        $database->bootEloquent();
        $app->instance('db', $database->getDatabaseManager());
        $app->instance('db.schema', $database->schema('testing'));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('procedures', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        $this->patient = Patient::query()->create(['first_name' => 'Anna', 'last_name' => 'Testová']);
        $this->service = new AdosProposalValidationService(new AdosProcedureRuleResolver);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    public function test_explicit_daily_frequencies_enforce_only_deterministic_maximums(): void
    {
        $cases = [
            ['3410', 'daily', false],
            ['3410', 'twice_daily', true],
            ['3414', 'daily', false],
            ['3414', 'twice_daily', true],
            ['3413', '5x_daily', false],
            ['3413', '6x_daily', true],
            ['3416', '4x_daily', false],
            ['3416', '5x_daily', true],
            ['3418', 'twice_daily', false],
            ['3418', 'three_times_daily', true],
            ['3424', 'twice_daily', false],
            ['3424', 'three_times_daily', true],
            ['3433', 'daily', false],
            ['3433', 'twice_daily', true],
            ['3436', '4x_daily', false],
            ['3436', '5x_daily', true],
        ];

        foreach ($cases as [$code, $frequency, $shouldFail]) {
            $result = $this->validate([$this->entry($code, $frequency)], ['patient_mobility' => ['I']]);
            self::assertSame($shouldFail, $result['errors'] !== [], "{$code} with {$frequency}");
        }
    }

    public function test_legacy_daily_alias_once_and_non_daily_schedules_do_not_create_false_errors(): void
    {
        foreach (['daily', 'once', 'weekdays', 'twice_weekly', 'once_monthly'] as $frequency) {
            $result = $this->validate([$this->entry('3410', $frequency)]);
            self::assertSame([], $result['errors'], $frequency);
        }

        $asNeeded = $this->validate([$this->entry('3410', 'as_needed')]);
        self::assertSame([], $asNeeded['errors']);
        self::assertSame('warning', $asNeeded['warnings'][0]['severity']);
    }

    public function test_central_frequency_definitions_expose_machine_readable_occurrences(): void
    {
        self::assertSame([
            'daily',
            'twice_daily',
            'three_times_daily',
            'weekdays',
            'every_other_day',
            'three_times_weekly',
            'twice_weekly',
            'once_weekly',
            'weekends',
            'twice_monthly',
            'once_monthly',
            'once',
            'as_needed',
        ], ClinicalFrequency::canonicalValues());
        self::assertSame(1, ClinicalFrequency::occurrencesForPeriod('daily', 'day'));
        self::assertSame(2, ClinicalFrequency::occurrencesForPeriod('twice_daily', 'day'));
        self::assertSame(3, ClinicalFrequency::occurrencesForPeriod('three_times_daily', 'day'));
        self::assertSame(3, ClinicalFrequency::occurrencesForPeriod('three_times_weekly', 'week'));
        self::assertSame(1, ClinicalFrequency::occurrencesForPeriod('once_monthly', 'month'));
        self::assertSame(1, ClinicalFrequency::occurrencesForPeriod('once', 'occurrence'));
    }

    public function test_historical_frequencies_and_recent_daily_aliases_remain_supported(): void
    {
        $historical = [
            'daily',
            'weekdays',
            'weekends',
            'every_other_day',
            'three_times_weekly',
            'twice_weekly',
            'once_weekly',
            'twice_monthly',
            'once_monthly',
            'as_needed',
        ];

        foreach ($historical as $frequency) {
            self::assertNotNull(ClinicalFrequency::definition($frequency), $frequency);
        }

        self::assertSame('daily', ClinicalFrequency::normalize('1x_daily'));
        self::assertSame('twice_daily', ClinicalFrequency::normalize('2x_daily'));
        self::assertSame('three_times_daily', ClinicalFrequency::normalize('3x_daily'));
    }

    public function test_cross_period_frequency_comparisons_do_not_create_false_errors(): void
    {
        foreach (['three_times_weekly', 'once_monthly', 'weekdays', 'every_other_day'] as $frequency) {
            $result = $this->validate([$this->entry('3410', $frequency)]);
            self::assertSame([], $result['errors'], $frequency);
        }
    }

    public function test_frontend_frequency_dropdown_uses_required_order(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/resources/js/pages/Patients/Proposal/PatientProposalPage.vue');
        self::assertIsString($source);
        self::assertSame(1, preg_match(
            '/const frequencyOptions = \[(.*?)\]\n\nconst oneTimeProcedureCodes/s',
            $source,
            $block,
        ));
        preg_match_all("/value:\s*'([^']+)'/", $block[1], $values);

        self::assertSame([
            'daily',
            'twice_daily',
            'three_times_daily',
            'weekdays',
            'every_other_day',
            'three_times_weekly',
            'twice_weekly',
            'once_weekly',
            'weekends',
            'twice_monthly',
            'once_monthly',
            'once',
            'as_needed',
        ], $values[1]);
    }

    public function test_monthly_limit_is_deterministic(): void
    {
        self::assertSame([], $this->validate([$this->entry('3394', 'once_monthly')])['errors']);
        self::assertCount(1, $this->validate([$this->entry('3394', 'twice_monthly')])['errors']);
    }

    public function test_scoring_forbidden_pairs_do_not_block_proposal_selections(): void
    {
        $pairs = [
            ['3390', '3394'],
            ['3393', '3394'],
            ['3393', '3395'],
            ['3394', '3395'],
            ['3400', '3424'],
            ['3413', '3422B'],
            ['3413', '3422C'],
            ['3418', '3419'],
            ['3422A', '3424'],
            ['3439', '3440'],
        ];

        foreach ($pairs as [$first, $second]) {
            $result = $this->validate([$this->entry($first), $this->entry($second)], [
                'patient_mobility' => ['I'],
            ]);

            self::assertSame([], $result['errors'], "{$first}+{$second}");
        }
    }

    public function test_ados_episode_procedure_combinations_are_valid_proposal_selections(): void
    {
        $combinations = [
            ['3390', '3393'],
            ['3390', '3394'],
            ['3390', '3395'],
            ['3393', '3394'],
            ['3393', '3395'],
            ['3394', '3395'],
            ['3390', '3393', '3394', '3395'],
            ['3439', '3440'],
        ];

        foreach ($combinations as $codes) {
            $result = $this->validate(array_map(fn (string $code): array => $this->entry($code), $codes), [
                'patient_mobility' => ['I'],
            ]);

            self::assertSame([], $result['errors'], implode('+', $codes));
        }
    }

    public function test_mobility_and_duration_rules_are_warnings_only(): void
    {
        $mobilityResult = $this->validate([
            $this->entry('3416'),
            $this->entry('3422A'),
        ], [
            'patient_mobility' => ['F'],
        ]);
        $durationResult = $this->validate([
            $this->entry('3424'),
        ], [
            'patient_mobility' => ['F'],
            'expected_duration' => 'six_months',
        ]);

        self::assertSame([], $mobilityResult['errors']);
        self::assertSame([], $durationResult['errors']);
        self::assertCount(2, $mobilityResult['warnings']);
        self::assertCount(2, $durationResult['warnings']);
    }

    public function test_mobility_mapping_matches_limited_and_immobile_rules(): void
    {
        self::assertSame([], $this->validate([$this->entry('3424')], ['patient_mobility' => ['H']])['warnings']);
        self::assertSame([], $this->validate([$this->entry('3424')], ['patient_mobility' => ['I']])['warnings']);
        self::assertCount(1, $this->validate([$this->entry('3424')], ['patient_mobility' => ['F']])['warnings']);
        self::assertSame([], $this->validate([$this->entry('3422A')], ['patient_mobility' => ['I']])['warnings']);
        self::assertCount(1, $this->validate([$this->entry('3422A')], ['patient_mobility' => ['H']])['warnings']);
    }

    public function test_duration_warning_applies_to_3424_and_3419_without_blocking(): void
    {
        $short = $this->validate([$this->entry('3424')], [
            'patient_mobility' => ['H'],
            'expected_duration' => 'three_months',
        ]);
        $longRehabilitation = $this->validate([$this->entry('3424')], [
            'patient_mobility' => ['H'],
            'expected_duration' => 'six_months',
        ]);
        $longInfusion = $this->validate([$this->entry('3419')], [
            'expected_duration' => 'over_six_months',
        ]);

        self::assertSame([], $short['warnings']);
        self::assertCount(1, $longRehabilitation['warnings']);
        self::assertCount(1, $longInfusion['warnings']);
        self::assertSame([], $longRehabilitation['errors']);
        self::assertSame([], $longInfusion['errors']);
    }

    public function test_isolated_care_warning_requires_exactly_one_simple_treatment(): void
    {
        $isolated = $this->validate([
            $this->entry('3390', 'once'),
            $this->entry('3416'),
        ], ['patient_mobility' => ['H']]);
        $broaderCare = $this->validate([
            $this->entry('3390', 'once'),
            $this->entry('3416'),
            $this->entry('3418'),
        ], ['patient_mobility' => ['H']]);

        self::assertCount(1, array_filter(
            $isolated['warnings'],
            fn (array $issue): bool => $issue['type'] === 'isolated_care',
        ));
        self::assertSame([], array_values(array_filter(
            $broaderCare['warnings'],
            fn (array $issue): bool => $issue['type'] === 'isolated_care',
        )));
    }

    public function test_3440_frequency_choices_are_non_blocking_and_keep_documentation_hint(): void
    {
        foreach (['daily', 'weekends', 'three_times_weekly'] as $frequency) {
            $result = $this->validate([$this->entry('3440', $frequency)]);

            self::assertSame([], $result['errors'], $frequency);
            self::assertNotEmpty(array_filter(
                $result['info'],
                fn (array $issue): bool => $issue['type'] === 'documentation_hint',
            ));
        }
    }

    public function test_rule_resolver_exposes_each_scope_without_leaking_scoring_into_proposal_rules(): void
    {
        $resolver = new AdosProcedureRuleResolver;

        self::assertSame('start', $resolver->episode('3390')['role']);
        self::assertSame(['new', 'restart'], $resolver->episode('3390')['allowed_when']);
        self::assertSame(['3394'], $resolver->scoring('3390')['forbidden_with']);
        self::assertArrayNotHasKey('forbidden_with', $resolver->proposal('3390'));
        self::assertSame([
            'outside_regular_working_hours' => true,
            'weekday_time_from' => '19:00',
            'weekday_time_to' => '07:00',
            'allowed_days' => ['saturday', 'sunday', 'holiday'],
        ], $resolver->scoring('3440')['schedule']);
    }

    public function test_documentation_schedule_and_episode_hints_never_block(): void
    {
        $result = $this->validate([
            $this->entry('3390', 'once'),
            $this->entry('3392A', 'once'),
            $this->entry('3395', 'once'),
            $this->entry('3419'),
            $this->entry('3440', 'weekends'),
        ], ['patient_mobility' => ['I']]);

        self::assertSame([], $result['errors']);
        self::assertNotEmpty($result['info']);
        self::assertNotEmpty(array_filter(
            $result['info'],
            fn (array $issue): bool => $issue['rule_code'] === 'ADOS-3440-DOCUMENTATION',
        ));
        self::assertNotEmpty(array_filter(
            $result['info'],
            fn (array $issue): bool => $issue['type'] === 'episode_context',
        ));
    }

    public function test_old_detail_payloads_are_accepted_but_not_required(): void
    {
        $withoutDetails = $this->validate([$this->entry('3416')], ['patient_mobility' => ['H']]);
        $withDetails = $this->validate([array_merge($this->entry('3416'), [
            'details' => ['medicine_name' => 'Historical value'],
        ])], ['patient_mobility' => ['H']]);

        self::assertSame([], $withoutDetails['errors']);
        self::assertSame([], $withDetails['errors']);
    }

    public function test_result_uses_one_shared_version_without_insurer_metadata(): void
    {
        $result = $this->validate([]);

        self::assertSame('1.1', $result['version']);
        self::assertSame('ADOS', $result['care_type']);
        self::assertArrayNotHasKey('insurer', $result);
        self::assertArrayNotHasKey('rule_set', $result);
    }

    /** @param array<int, array<string, mixed>> $procedures @param array<string, mixed> $overrides */
    private function validate(array $procedures, array $overrides = []): array
    {
        return $this->service->validate($this->patient, array_merge([
            'date' => '2026-10-18',
            'patient_mobility' => [],
            'expected_duration' => 'three_months',
            'procedures' => $procedures,
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private function entry(string $code, string $frequency = 'once_weekly'): array
    {
        return [
            'procedure_id' => $this->procedure($code)->id,
            'frequency' => $frequency,
        ];
    }

    private function procedure(string $code): Procedure
    {
        return $this->procedures[$code] ??= Procedure::query()->create([
            'code' => $code,
            'description' => "Procedure {$code}",
        ]);
    }
}
