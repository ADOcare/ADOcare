<?php

namespace Tests\Feature;

use App\Console\Commands\CheckPatientStatuses;
use App\Models\Patient;
use App\Services\EoverenieInsuranceService;
use App\Services\PatientNotificationService;
use App\Services\UdzsDeathService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class CheckPatientStatusesTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new Application(dirname(__DIR__, 2));
        Container::setInstance($this->app);
        $this->app->instance('config', new Repository);

        $database = new Manager($this->app);
        $database->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $database->setAsGlobal();
        $database->bootEloquent();
        $this->app->instance('db', $database->getDatabaseManager());
        $this->app->bind('db.schema', fn () => $database->schema());

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);

        Schema::create('patients', function ($table): void {
            $table->id();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('personal_number')->nullable();
            $table->date('death_date')->nullable();
            $table->unsignedInteger('branch_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('branches', function ($table): void {
            $table->id();
            $table->unsignedInteger('company_id')->nullable();
            $table->timestamps();
        });
        Schema::create('insurance_companies', function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->nullable();
            $table->timestamps();
        });
        Schema::create('patient_coverages', function ($table): void {
            $table->id();
            $table->unsignedInteger('patient_id')->unique();
            $table->unsignedInteger('insurance_company_id')->nullable();
            $table->string('category');
            $table->string('identification_method');
            $table->string('member_state_code')->nullable();
            $table->string('foreign_insured_id')->nullable();
            $table->string('special_category')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        parent::tearDown();
    }

    public function test_living_scope_excludes_deceased_and_soft_deleted_patients(): void
    {
        $this->insertPatient(1);
        $this->insertPatient(2, deathDate: '2026-10-01');
        $this->insertPatient(3, deletedAt: '2026-10-02 08:00:00');

        self::assertSame([1], Patient::query()->living()->pluck('id')->all());
    }

    public function test_scheduled_checks_skip_deceased_and_soft_deleted_patients(): void
    {
        $this->insertPatient(1, deathDate: '2026-10-01');
        $this->insertPatient(2, deletedAt: '2026-10-02 08:00:00');

        $deathService = $this->createMock(UdzsDeathService::class);
        $deathService->expects(self::never())->method('checkPersonalNumber');

        $insuranceService = $this->createMock(EoverenieInsuranceService::class);
        $insuranceService->expects(self::never())->method('verify');

        $notificationService = $this->createMock(PatientNotificationService::class);

        $this->app->instance(UdzsDeathService::class, $deathService);
        $this->app->instance(EoverenieInsuranceService::class, $insuranceService);
        $this->app->instance(PatientNotificationService::class, $notificationService);

        $command = new CheckPatientStatuses;
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Checked 0 patient(s)', $tester->getDisplay());
    }

    public function test_scheduled_checks_do_not_call_slovak_registries_for_eu_patient(): void
    {
        $this->insertPatient(1, personalNumber: null);
        $this->insertCoverage(1, 'eu', 'foreign_triad');

        $deathService = $this->createMock(UdzsDeathService::class);
        $deathService->expects(self::never())->method('checkPersonalNumber');

        $insuranceService = $this->createMock(EoverenieInsuranceService::class);
        $insuranceService->expects(self::never())->method('verify');

        $notificationService = $this->createMock(PatientNotificationService::class);
        $notificationService->expects(self::once())
            ->method('resolveIssue')
            ->with(self::isInstanceOf(Patient::class), 'patient:1:insurance');

        $this->app->instance(UdzsDeathService::class, $deathService);
        $this->app->instance(EoverenieInsuranceService::class, $insuranceService);
        $this->app->instance(PatientNotificationService::class, $notificationService);

        $command = new CheckPatientStatuses;
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Checked 1 patient(s)', $tester->getDisplay());
    }

    public function test_scheduled_checks_still_verify_domestic_patient(): void
    {
        $this->insertPatient(1);
        $this->insertCoverage(1, 'domestic', 'slovak_identifier');

        $deathService = $this->createMock(UdzsDeathService::class);
        $deathService->expects(self::once())
            ->method('checkPersonalNumber')
            ->with('0000000001')
            ->willReturn(['status' => 'alive']);

        $insuranceService = $this->createMock(EoverenieInsuranceService::class);
        $insuranceService->expects(self::once())
            ->method('verify')
            ->with(
                self::isInstanceOf(Patient::class),
                self::callback(fn ($coverage) => $coverage?->category?->value === 'domestic'),
            )
            ->willReturn(['status' => 'verified']);

        $notificationService = $this->createMock(PatientNotificationService::class);
        $notificationService->expects(self::once())
            ->method('resolveIssue')
            ->with(self::isInstanceOf(Patient::class), 'patient:1:insurance');

        $this->app->instance(UdzsDeathService::class, $deathService);
        $this->app->instance(EoverenieInsuranceService::class, $insuranceService);
        $this->app->instance(PatientNotificationService::class, $notificationService);

        $command = new CheckPatientStatuses;
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute([]));
        self::assertTrue((bool) DB::table('patient_coverages')->where('patient_id', 1)->value('is_verified'));
    }

    private function insertPatient(
        int $id,
        ?string $deathDate = null,
        ?string $deletedAt = null,
        ?string $personalNumber = null,
    ): void {
        DB::table('patients')->insert([
            'id' => $id,
            'first_name' => 'Patient',
            'last_name' => (string) $id,
            'personal_number' => func_num_args() >= 4
                ? $personalNumber
                : str_pad((string) $id, 10, '0', STR_PAD_LEFT),
            'death_date' => $deathDate,
            'created_at' => '2026-01-01 08:00:00',
            'updated_at' => '2026-01-01 08:00:00',
            'deleted_at' => $deletedAt,
        ]);
    }

    private function insertCoverage(int $patientId, string $category, string $identificationMethod): void
    {
        DB::table('patient_coverages')->insert([
            'patient_id' => $patientId,
            'insurance_company_id' => null,
            'category' => $category,
            'identification_method' => $identificationMethod,
            'member_state_code' => $identificationMethod === 'foreign_triad' ? 'CZ' : null,
            'foreign_insured_id' => $identificationMethod === 'foreign_triad' ? 'CZ001' : null,
            'special_category' => null,
            'is_verified' => false,
            'created_at' => '2026-01-01 08:00:00',
            'updated_at' => '2026-01-01 08:00:00',
        ]);
    }
}
