<?php

namespace Tests\Feature;

use App\Http\Filters\ApiQuery;
use App\Http\Resources\PatientTableResource;
use App\Models\Branch;
use App\Models\Patient;
use App\Models\User;
use App\Services\EoverenieInsuranceService;
use App\Services\PatientService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class PatientSelectorQueryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container;
        Container::setInstance($app);
        $app->instance('config', new Repository);

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
            $table->string('identificator')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
        });
        Schema::create('users', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
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
            $table->date('death_date')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('nurse_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('patient_audit_issues', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('patient_id');
            $table->string('status');
        });

        DB::table('branches')->insert([
            ['id' => 1, 'company_id' => 10, 'code' => 'BA', 'city' => 'Bratislava'],
            ['id' => 2, 'company_id' => 10, 'code' => 'TT', 'city' => 'Trnava'],
            ['id' => 3, 'company_id' => 20, 'code' => 'NR', 'city' => 'Nitra'],
        ]);
        DB::table('users')->insert([
            [
                'id' => 1,
                'company_id' => 10,
                'first_name' => 'Aktívna',
                'last_name' => 'Sestra',
                'created_at' => '2026-01-01 08:00:00',
                'updated_at' => '2026-01-01 08:00:00',
            ],
            [
                'id' => 2,
                'company_id' => 10,
                'first_name' => 'Iná',
                'last_name' => 'Sestra',
                'created_at' => '2026-01-01 08:00:00',
                'updated_at' => '2026-01-01 08:00:00',
            ],
        ]);
        DB::table('patients')->insert([
            $this->patient(1, 'Jana', 'Novakova', 1, 1),
            $this->patient(2, 'Jana', 'Novakova', 1, 2),
            $this->patient(3, 'Jana', 'Novakova', 2, 2),
            $this->patient(4, 'Jana', 'Novakova', 3, 2),
            [
                ...$this->patient(5, 'Jana', 'Novakova', 1, 1),
                'deleted_at' => '2026-10-01 08:00:00',
            ],
            $this->patient(6, 'Šimon', 'Žáček', 1, 1),
        ]);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        parent::tearDown();
    }

    public function test_empty_search_returns_only_current_assignment(): void
    {
        $results = $this->service()
            ->queryForPatientSelector(User::findOrFail(1), Branch::findOrFail(1))
            ->pluck('id')
            ->all();

        self::assertSame([1, 6], $results);
    }

    public function test_search_prioritizes_current_assignment_and_includes_company_matches(): void
    {
        $results = $this->service()
            ->queryForPatientSelector(User::findOrFail(1), Branch::findOrFail(1), 'Jana Novak')
            ->pluck('id')
            ->all();

        self::assertSame([1, 2, 3], $results);
    }

    public function test_selector_search_ignores_diacritics(): void
    {
        $results = $this->service()
            ->queryForPatientSelector(User::findOrFail(1), Branch::findOrFail(1), 'Simon Zacek')
            ->pluck('id')
            ->all();

        self::assertSame([6], $results);
    }

    public function test_patient_table_search_ignores_diacritics(): void
    {
        $query = Patient::query();
        $applySearch = new ReflectionMethod(ApiQuery::class, 'applySearch');
        $applySearch->invoke(
            null,
            new Request(['q' => 'Simon Zacek']),
            $query,
            ['first_name', 'last_name', 'personal_number'],
            [],
        );

        self::assertSame([6], $query->pluck('id')->all());
    }

    public function test_patient_table_query_does_not_eager_load_full_patient_graph(): void
    {
        $query = $this->service()->queryForUserBranch(
            User::findOrFail(1),
            Branch::findOrFail(1),
        );

        self::assertSame([], $query->getEagerLoads());

        $patient = $query->with(['nurse', 'branch'])->firstOrFail();
        $data = (new PatientTableResource($patient))->toArray(new Request);

        self::assertSame(1, $data['id']);
        self::assertSame('Aktívna', $data['nurse']['first_name']);
        self::assertSame('BA', $data['branch']['code']);
        self::assertArrayNotHasKey('doctor', $data);
        self::assertArrayNotHasKey('visits', $data);
        self::assertArrayNotHasKey('insurance_company', $data);
    }

    private function service(): PatientService
    {
        return new PatientService(
            $this->createMock(EoverenieInsuranceService::class),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function patient(
        int $id,
        string $firstName,
        string $lastName,
        int $branchId,
        int $nurseId,
    ): array {
        return [
            'id' => $id,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'personal_number' => '900101/'.$id,
            'branch_id' => $branchId,
            'nurse_id' => $nurseId,
            'created_at' => '2026-01-01 08:00:00',
            'updated_at' => '2026-01-01 08:00:00',
            'deleted_at' => null,
        ];
    }
}
