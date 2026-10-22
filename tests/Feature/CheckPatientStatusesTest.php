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
        $this->app->instance('config', new Repository());

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
            $table->timestamps();
            $table->softDeletes();
        });
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        parent::tearDown();
    }

    public function test_active_scope_excludes_deceased_and_soft_deleted_patients(): void
    {
        $this->insertPatient(1);
        $this->insertPatient(2, deathDate: '2026-10-01');
        $this->insertPatient(3, deletedAt: '2026-10-02 08:00:00');

        self::assertSame([1], Patient::query()->active()->pluck('id')->all());
    }

    public function test_scheduled_checks_do_not_call_services_for_inactive_patients(): void
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

        $command = new CheckPatientStatuses();
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Checked 0 patient(s)', $tester->getDisplay());
    }

    private function insertPatient(
        int $id,
        ?string $deathDate = null,
        ?string $deletedAt = null,
    ): void {
        DB::table('patients')->insert([
            'id' => $id,
            'first_name' => 'Patient',
            'last_name' => (string) $id,
            'personal_number' => str_pad((string) $id, 10, '0', STR_PAD_LEFT),
            'death_date' => $deathDate,
            'created_at' => '2026-01-01 08:00:00',
            'updated_at' => '2026-01-01 08:00:00',
            'deleted_at' => $deletedAt,
        ]);
    }
}
