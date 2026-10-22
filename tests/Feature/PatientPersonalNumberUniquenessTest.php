<?php

namespace Tests\Feature;

use App\Services\EoverenieInsuranceService;
use App\Services\PatientService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class PatientPersonalNumberUniquenessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        Container::setInstance($app);
        $app->instance('config', new Repository());
        $translator = new Translator(new ArrayLoader(), 'sk');
        $app->instance('translator', $translator);
        $app->instance('validator', new Factory($translator, $app));

        $database = new Manager($app);
        $database->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $database->setAsGlobal();
        $database->bootEloquent();
        $app->instance('db', $database->getDatabaseManager());
        $app->bind('db.schema', fn () => $database->schema());

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        Schema::create('branches', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('code')->nullable();
            $table->string('city')->nullable();
            $table->string('address')->nullable();
        });
        Schema::create('users', function ($table): void {
            $table->id();
            $table->string('title')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('patients', function ($table): void {
            $table->id();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('personal_number')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('nurse_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        DB::table('branches')->insert([
            ['id' => 1, 'company_id' => 7, 'code' => 'BA1', 'city' => 'Bratislava'],
            ['id' => 2, 'company_id' => 7, 'code' => 'TT1', 'city' => 'Trnava'],
        ]);
        DB::table('users')->insert([
            'id' => 20,
            'title' => 'Mgr.',
            'first_name' => 'Ina',
            'last_name' => 'Sestra',
            'created_at' => '2026-01-01 08:00:00',
            'updated_at' => '2026-01-01 08:00:00',
        ]);
        DB::table('patients')->insert([
            'id' => 100,
            'first_name' => 'Jana',
            'last_name' => 'Obnovitelna',
            'personal_number' => '900101/1234',
            'branch_id' => 2,
            'nurse_id' => 20,
            'created_at' => '2026-01-01 08:00:00',
            'updated_at' => '2026-01-01 08:00:00',
            'deleted_at' => '2026-10-01 08:00:00',
        ]);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        parent::tearDown();
    }

    public function test_deleted_patient_of_another_nurse_in_company_blocks_duplicate_creation(): void
    {
        $service = new PatientService(
            $this->createMock(EoverenieInsuranceService::class),
        );
        $assertUnique = new ReflectionMethod($service, 'assertUniquePersonalNumberWithinCompany');

        try {
            $assertUnique->invoke($service, '9001011234', 7);
            self::fail('Expected duplicate personal number validation to fail.');
        } catch (ValidationException $exception) {
            $message = $exception->errors()['personal_number'][0] ?? '';

            self::assertStringContainsString('medzi odstránenými pacientmi', $message);
            self::assertStringContainsString('Ina Sestra', $message);
            self::assertStringContainsString('Obnovte existujúceho pacienta', $message);
        }
    }
}
