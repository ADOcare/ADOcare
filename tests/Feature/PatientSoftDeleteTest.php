<?php

namespace Tests\Feature;

use App\Services\Claims\InsuredClaimResolver;
use App\Services\EoverenieInsuranceService;
use App\Services\PatientService;
use App\Services\PointClaimSelectionService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class PatientSoftDeleteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        Container::setInstance($app);
        $app->instance('config', new Repository());

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

        $this->createTables();
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        parent::tearDown();
    }

    public function test_soft_delete_preserves_related_data_and_excludes_patient_from_new_exports(): void
    {
        DB::table('patients')->insert([
            [
                'id' => 1,
                'first_name' => 'Active',
                'last_name' => 'Patient',
                'personal_number' => '1111111111',
                'sex' => 'F',
                'doctor_id' => 1,
                'deleted_at' => null,
            ],
            [
                'id' => 2,
                'first_name' => 'Deleted',
                'last_name' => 'Patient',
                'personal_number' => '2222222222',
                'sex' => 'M',
                'doctor_id' => 1,
                'deleted_at' => null,
            ],
        ]);
        DB::table('doctors')->insert(['id' => 1, 'pzs' => 'PZS', 'zpr' => 'ZPR']);
        DB::table('patient_coverages')->insert([
            ['id' => 1, 'patient_id' => 1, 'insurance_company_id' => 1, 'category' => 'domestic'],
            ['id' => 2, 'patient_id' => 2, 'insurance_company_id' => 1, 'category' => 'domestic'],
        ]);
        DB::table('procedure_company_prices')->insert([
            'procedure_id' => 1,
            'insurance_company_id' => 1,
            'company_id' => 1,
            'price' => 5,
        ]);
        DB::table('patient_points')->insert([
            $this->point(1, 1),
            $this->point(2, 2),
        ]);
        DB::table('documents')->insert(['id' => 1, 'patient_id' => 2]);

        $patientService = new PatientService(
            $this->createMock(EoverenieInsuranceService::class),
        );
        $patientService->deleteManyByIds([2]);

        self::assertNotNull(DB::table('patients')->where('id', 2)->value('deleted_at'));
        self::assertSame(1, DB::table('patient_points')->where('patient_id', 2)->count());
        self::assertSame(1, DB::table('documents')->where('patient_id', 2)->count());

        $selection = new PointClaimSelectionService(new InsuredClaimResolver());
        $queryRows = new ReflectionMethod($selection, 'queryRows');
        $rows = $queryRows->invoke($selection, [
            'healthcare_worker_id' => 1,
            'branch_id' => 1,
            'company_id' => 1,
            'insurance_company_id' => 1,
            'category' => 'domestic',
            'from' => '2026-10-01',
            'to' => '2026-10-31',
        ]);

        self::assertSame([1], $rows->pluck('patient_id')->all());
    }

    private function point(int $id, int $patientId): array
    {
        return [
            'id' => $id,
            'date' => '2026-10-10',
            'reference_date' => '2026-10-01',
            'patient_id' => $patientId,
            'diagnosis_code' => 'I10',
            'procedure_code' => '3413',
            'procedure_id' => 1,
            'quantity' => 1,
            'doctor_pzs' => null,
            'doctor_zpr' => null,
            'user_id' => 1,
            'branch_id' => 1,
            'created_at' => '2026-10-10 08:00:00',
            'updated_at' => '2026-10-10 08:00:00',
        ];
    }

    private function createTables(): void
    {
        Schema::create('patients', function ($table) {
            $table->id();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('personal_number')->nullable();
            $table->string('sex')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedBigInteger('doctor_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('patient_points', function ($table) {
            $table->id();
            $table->date('date')->nullable();
            $table->date('reference_date')->nullable();
            $table->unsignedBigInteger('patient_id');
            $table->string('diagnosis_code')->nullable();
            $table->string('procedure_code')->nullable();
            $table->unsignedBigInteger('procedure_id')->nullable();
            $table->integer('quantity')->nullable();
            $table->string('doctor_pzs')->nullable();
            $table->string('doctor_zpr')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('branch_id');
            $table->timestamps();
        });
        Schema::create('documents', function ($table) {
            $table->id();
            $table->unsignedBigInteger('patient_id');
        });
        Schema::create('doctors', function ($table) {
            $table->id();
            $table->string('pzs')->nullable();
            $table->string('zpr')->nullable();
        });
        Schema::create('patient_coverages', function ($table) {
            $table->id();
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('insurance_company_id');
            $table->string('identification_method')->nullable();
            $table->string('category');
            $table->string('member_state_code')->nullable();
            $table->string('foreign_insured_id')->nullable();
            $table->string('special_category')->nullable();
        });
        Schema::create('procedure_company_prices', function ($table) {
            $table->id();
            $table->unsignedBigInteger('procedure_id');
            $table->unsignedBigInteger('insurance_company_id');
            $table->unsignedBigInteger('company_id');
            $table->decimal('price', 10, 2);
        });
    }
}
