<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Services\EoverenieInsuranceService;
use App\Services\Patients\PatientHistoryResolver;
use App\Services\Patients\PatientHistoryJournal;
use App\Services\Patients\PatientHistoryWriter;
use App\Services\PatientService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;

class PatientHistoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container;
        Container::setInstance($app);
        $app->instance('config', new Repository);
        $database = new Manager($app);
        $database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $database->setAsGlobal();
        $database->bootEloquent();
        $app->instance('db', $database->getDatabaseManager());
        $app->bind('db.schema', fn () => $database->schema());
        $app->instance('translator', new Translator(new ArrayLoader, 'sk'));

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        Schema::create('patients', function ($table): void {
            $table->increments('id');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('title')->nullable();
            $table->string('personal_number')->nullable();
            $table->string('sex')->nullable();
            $table->string('contact')->nullable();
            $table->string('country_code_phone')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('zip')->nullable();
            $table->unsignedBigInteger('country_id')->nullable();
            $table->double('latitude')->nullable();
            $table->double('longitude')->nullable();
            $table->unsignedBigInteger('doctor_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('nurse_id')->nullable();
            $table->unsignedBigInteger('insurance_company_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('users', function ($table): void {
            $table->increments('id');
            $table->string('title')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->timestamps();
        });
        Schema::create('branches', function ($table): void {
            $table->increments('id');
            $table->string('code')->nullable();
            $table->string('city')->nullable();
            $table->string('address')->nullable();
            $table->timestamps();
        });

        $historyFields = [
            'patient_name_history' => ['first_name', 'last_name', 'title'],
            'patient_address_history' => ['address', 'city', 'zip', 'country_id', 'latitude', 'longitude'],
            'patient_doctor_history' => ['doctor_id'],
            'patient_nurse_history' => ['nurse_id'],
            'patient_branch_history' => ['branch_id'],
            'patient_identity_history' => ['personal_number', 'sex'],
            'patient_contact_history' => ['contact', 'country_code_phone', 'phone'],
        ];

        foreach ($historyFields as $tableName => $fields) {
            Schema::create($tableName, function ($table) use ($fields): void {
                $table->bigIncrements('id');
                $table->unsignedInteger('patient_id');
                foreach ($fields as $field) {
                    if (in_array($field, ['country_id', 'doctor_id', 'nurse_id', 'branch_id'], true)) {
                        $table->unsignedBigInteger($field)->nullable();
                    } elseif (in_array($field, ['latitude', 'longitude'], true)) {
                        $table->double($field)->nullable();
                    } else {
                        $table->string($field)->nullable();
                    }
                }
                $table->date('valid_from')->nullable();
                $table->date('valid_to')->nullable();
                $table->unsignedInteger('changed_by')->nullable();
                $table->string('change_reason')->nullable();
                $table->timestamps();
            });
        }

        Schema::create('patient_coverages', function ($table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('patient_id');
            $table->unsignedBigInteger('insurance_company_id')->nullable();
            $table->string('category')->nullable();
            $table->string('identification_method')->nullable();
            $table->string('member_state_code')->nullable();
            $table->string('foreign_insured_id')->nullable();
            $table->string('special_category')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedInteger('changed_by')->nullable();
            $table->string('change_reason')->nullable();
            $table->timestamps();
        });
        Schema::create('patient_history_corrections', function ($table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('patient_id');
            $table->string('category');
            $table->unsignedBigInteger('record_id');
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->json('before_values');
            $table->json('after_values');
            $table->unsignedInteger('changed_by')->nullable();
            $table->unsignedBigInteger('history_change_id')->nullable();
            $table->string('reason');
            $table->timestamps();
        });
        Schema::create('patient_history_changes', function ($table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('patient_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('change_type');
            $table->timestamp('recorded_at');
            $table->string('reason')->nullable();
            $table->json('snapshot');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('patient_history_change_items', function ($table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('history_change_id');
            $table->string('category');
            $table->json('previous_values')->nullable();
            $table->json('new_values');
            $table->date('effective_date')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        DB::table('patients')->insert([
            'id' => 10,
            'first_name' => 'Jana',
            'last_name' => 'Nová',
            'title' => null,
            'personal_number' => '9001011234',
            'sex' => 'F',
            'nurse_id' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('users')->insert([
            'id' => 5,
            'first_name' => 'Eva',
            'last_name' => 'Sestra',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => 7,
            'code' => 'BA',
            'city' => 'Bratislava',
            'address' => 'Hlavná 1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        parent::tearDown();
    }

    public function test_legacy_periods_are_uncertain_and_change_splits_at_inclusive_boundary(): void
    {
        $patient = $this->patient();
        $writer = new PatientHistoryWriter;
        $resolver = new PatientHistoryResolver;
        $writer->initialize($patient, null, null);
        $writer->apply($patient, ['first_name' => 'Jana Mária'], '2025-01-10', 5, 'Zmena mena');

        self::assertSame('unknown', $resolver->nameOn($patient, '2025-01-09')['status']);
        self::assertSame('Jana', $resolver->nameOn($patient, '2025-01-09')['values']['first_name']);
        self::assertSame('known', $resolver->nameOn($patient, '2025-01-10')['status']);
        self::assertSame('Jana Mária', $resolver->nameOn($patient, '2025-01-10')['values']['first_name']);
    }

    public function test_future_period_does_not_change_state_before_its_effective_date(): void
    {
        $patient = $this->patient();
        $writer = new PatientHistoryWriter;
        $resolver = new PatientHistoryResolver;
        $writer->initialize($patient, null, null);
        $writer->apply($patient, ['nurse_id' => 9], '2027-01-01', 5, null);

        self::assertSame(5, (int) $resolver->nurseOn($patient, '2026-11-12')['values']['nurse_id']);
        self::assertSame(9, (int) $resolver->nurseOn($patient, '2027-01-01')['values']['nurse_id']);
    }

    public function test_grouped_effective_dates_create_one_immutable_revision(): void
    {
        Carbon::setTestNow('2026-03-15 09:42:00');
        $patient = $this->patient();
        $writer = new PatientHistoryWriter;
        $resolver = new PatientHistoryResolver;
        $journal = new PatientHistoryJournal($resolver);
        $writer->initialize($patient, null, null);

        $items = $writer->apply(
            $patient,
            ['address' => 'Hlavná 12', 'doctor_id' => 12],
            '2026-03-12',
            5,
            'Aktualizácia údajov',
            ['doctor' => '2026-03-10'],
        );
        $revisionId = $journal->record($patient, 'change', 5, $items, 'Aktualizácia údajov');

        self::assertNotNull($revisionId);
        self::assertSame(1, DB::table('patient_history_changes')->count());
        self::assertSame(2, DB::table('patient_history_change_items')->count());
        self::assertSame(
            ['2026-03-10', '2026-03-12'],
            DB::table('patient_history_change_items')->orderBy('effective_date')->pluck('effective_date')->all(),
        );

        $firstSnapshot = DB::table('patient_history_changes')->where('id', $revisionId)->value('snapshot');
        $laterItems = $writer->apply($patient, ['address' => 'Nová 20'], '2026-04-01', 5, null);
        $journal->record($patient, 'change', 5, $laterItems);

        self::assertSame(
            $firstSnapshot,
            DB::table('patient_history_changes')->where('id', $revisionId)->value('snapshot'),
        );
        self::assertSame('Hlavná 12', json_decode($firstSnapshot, true)['address']['values']['address']);
    }

    public function test_legacy_revision_snapshots_receive_assignment_display_names_without_mutation(): void
    {
        $patient = $this->patient();
        $snapshot = [
            'nurse' => [
                'status' => 'known',
                'values' => ['nurse_id' => 5],
                'valid_from' => null,
                'valid_to' => null,
            ],
            'branch' => [
                'status' => 'known',
                'values' => ['branch_id' => 7],
                'valid_from' => null,
                'valid_to' => null,
            ],
        ];
        $storedSnapshot = json_encode($snapshot, JSON_THROW_ON_ERROR);

        DB::table('patient_history_changes')->insert([
            'patient_id' => $patient->id,
            'user_id' => null,
            'change_type' => 'baseline',
            'recorded_at' => now(),
            'reason' => null,
            'snapshot' => $storedSnapshot,
            'metadata' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $revision = (new PatientHistoryJournal(new PatientHistoryResolver))
            ->revisionsFor($patient)[0];

        self::assertSame('Eva Sestra', $revision['snapshot']['nurse']['display_values']['nurse_id']);
        self::assertSame(
            'BA · Bratislava · Hlavná 1',
            $revision['snapshot']['branch']['display_values']['branch_id'],
        );
        self::assertSame(
            $storedSnapshot,
            DB::table('patient_history_changes')->value('snapshot'),
        );
    }

    public function test_all_history_categories_and_insurance_resolve_at_the_period_boundary(): void
    {
        $patient = $this->patient();
        DB::table('patient_coverages')->insert([
            'patient_id' => $patient->id,
            'insurance_company_id' => 1,
            'category' => 'domestic',
            'identification_method' => 'slovak_identifier',
            'is_verified' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $writer = new PatientHistoryWriter;
        $resolver = new PatientHistoryResolver;
        $writer->initialize($patient, null, null);
        $writer->apply($patient, [
            'first_name' => 'Jana Mária',
            'address' => 'Nová 1',
            'city' => 'Bratislava',
            'zip' => '81101',
            'country_id' => 207,
            'latitude' => 48.1,
            'longitude' => 17.1,
            'doctor_id' => 30,
            'nurse_id' => 9,
            'branch_id' => 4,
            'personal_number' => '900101/1234',
            'contact' => 'jana@example.test',
            'country_code_phone' => '+421',
            'phone' => '900123456',
        ], '2025-01-10', 5, 'Zmena údajov');
        $writer->applyCoverage($patient, [
            'insurance_company_id' => 2,
            'category' => 'domestic',
            'identification_method' => 'slovak_identifier',
            'is_verified' => false,
        ], '2025-01-10', 5, 'Zmena poisťovne');

        $before = $resolver->patientOn($patient, '2025-01-09');
        $after = $resolver->patientOn($patient, '2025-01-10');

        self::assertSame(1, (int) $before['insurance']['values']['insurance_company_id']);
        self::assertSame(2, (int) $after['insurance']['values']['insurance_company_id']);
        self::assertSame('Jana Mária', $after['name']['values']['first_name']);
        self::assertSame(30, (int) $after['doctor']['values']['doctor_id']);
        self::assertSame(9, (int) $after['nurse']['values']['nurse_id']);
        self::assertSame(4, (int) $after['branch']['values']['branch_id']);
        self::assertSame('Nová 1', $after['address']['values']['address']);
        self::assertSame(207, (int) $after['address']['values']['country_id']);
        self::assertSame('900101/1234', $after['identity']['values']['personal_number']);
        self::assertSame('jana@example.test', $after['contact']['values']['contact']);
    }

    public function test_overlapping_periods_are_reported_as_conflicts(): void
    {
        $patient = $this->patient();
        $writer = new PatientHistoryWriter;
        $resolver = new PatientHistoryResolver;
        $writer->initialize($patient, null, null);
        DB::table('patient_doctor_history')->insert([
            'patient_id' => $patient->id,
            'doctor_id' => 22,
            'valid_from' => '2025-01-01',
            'valid_to' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        self::assertSame('conflict', $resolver->doctorOn($patient, '2026-01-01')['status']);
    }

    public function test_correction_keeps_before_and_after_values_auditable(): void
    {
        $patient = $this->patient();
        $writer = new PatientHistoryWriter;
        $resolver = new PatientHistoryResolver;
        $writer->initialize($patient, '2020-01-01', 5);
        $recordId = (int) DB::table('patient_name_history')->value('id');

        $writer->correct($patient, 'name', $recordId, ['first_name' => 'Jana M.'], 5, 'Oprava preklepu');
        $entries = $resolver->historyFor($patient);
        $correction = collect($entries)->firstWhere('is_correction', true);

        self::assertSame('Jana', $correction['previous_values']['first_name']);
        self::assertSame('Jana M.', $correction['new_values']['first_name']);
        self::assertSame('Oprava preklepu', $correction['reason']);
        self::assertSame('Jana M.', $resolver->nameOn($patient, '2026-01-01')['values']['first_name']);
    }

    public function test_scheduled_projection_advances_when_the_effective_date_arrives(): void
    {
        Carbon::setTestNow('2026-11-12 00:00:00');
        $patient = $this->patient();
        $writer = new PatientHistoryWriter;
        $writer->initialize($patient, null, null);
        $writer->apply($patient, ['first_name' => 'Jana Mária'], '2027-01-01', 5, null);
        $service = new PatientService($this->createMock(EoverenieInsuranceService::class), $writer);

        $service->syncCurrentProjection($patient);
        self::assertSame('Jana', DB::table('patients')->where('id', 10)->value('first_name'));

        Carbon::setTestNow('2027-01-01 00:05:00');
        self::assertSame(1, $service->syncCurrentProjections());
        self::assertSame('Jana Mária', DB::table('patients')->where('id', 10)->value('first_name'));
    }

    private function patient(): Patient
    {
        return Patient::query()->findOrFail(10);
    }
}
