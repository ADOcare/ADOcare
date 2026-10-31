<?php

namespace Tests\Feature;

use App\Http\Resources\AdosEpisodeResource;
use App\Models\AdosEpisode;
use App\Models\AdosEpisodeType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Policies\PatientPolicy;
use App\Services\AdosEpisodeAccessService;
use App\Services\AdosEpisodeConfigurationService;
use App\Services\AdosEpisodeService;
use Carbon\Carbon;
use Database\Seeders\AdosEpisodeConfigurationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidatorFactory;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

class AdosEpisodeServiceTest extends TestCase
{
    private Company $company;

    private Branch $branch;

    private Patient $patient;

    private User $nurse;

    private AdosEpisodeType $firstType;

    private AdosEpisodeType $secondType;

    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container;
        Container::setInstance($app);
        $app->instance('config', new Repository(['database.default' => 'testing']));
        $translator = new Translator(new ArrayLoader, 'en');
        $app->instance('translator', $translator);
        $app->instance('validator', new ValidatorFactory($translator, $app));

        $database = new Manager($app);
        $database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'testing');
        $database->setAsGlobal();
        $database->bootEloquent();
        $app->instance('db', $database->getDatabaseManager());
        $app->instance('db.schema', $database->schema('testing'));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        $this->createBaseTables();
        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_30_010000_create_ados_episode_tables.php';
        $migration->up();

        $this->company = Company::query()->create(['name' => 'Test company']);
        $this->branch = Branch::query()->create(['company_id' => $this->company->id]);
        $nurseRole = Role::query()->create(['position' => 'nurse', 'scope' => 'branch']);
        $this->nurse = User::query()->create([
            'company_id' => $this->company->id,
            'email' => 'nurse@example.test',
        ]);
        DB::table('user_branches')->insert([
            'user_id' => $this->nurse->id,
            'branch_id' => $this->branch->id,
            'role_id' => $nurseRole->id,
        ]);
        $this->patient = Patient::query()->create([
            'first_name' => 'Test',
            'last_name' => 'Patient',
            'branch_id' => $this->branch->id,
            'nurse_id' => $this->nurse->id,
        ]);

        $this->firstType = $this->createType('Type one', 'type_one', 30);
        $this->secondType = $this->createType('Type two', 'type_two');
        Carbon::setTestNow('2026-10-30 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    public function test_start_defaults_expected_end_and_records_initial_type_history(): void
    {
        $service = new AdosEpisodeService(new PatientPolicy);
        $episode = $service->startEpisode(
            $this->patient,
            $this->firstType,
            Carbon::parse('2026-10-01'),
            $this->nurse,
        );

        self::assertSame('2026-10-31', $episode->expected_end_at->toDateString());
        self::assertSame('active', $episode->status);
        self::assertSame(1, $episode->typeHistory()->count());
        self::assertSame('2026-10-01', $episode->typeHistory()->first()->valid_from->toDateString());
        self::assertSame($this->nurse->id, $episode->created_by);
    }

    public function test_explicit_null_expected_end_overrides_default_and_active_episode_is_unique(): void
    {
        $service = new AdosEpisodeService(new PatientPolicy);
        $episode = $service->startEpisode(
            $this->patient,
            $this->firstType,
            Carbon::parse('2026-10-01'),
            $this->nurse,
            null,
            true,
        );

        self::assertNull($episode->expected_end_at);
        try {
            $service->startEpisode(
                $this->patient,
                $this->secondType,
                Carbon::parse('2026-10-02'),
                $this->nurse,
            );
            self::fail('A second active episode should be rejected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('patient', $exception->errors());
        }

        $this->expectException(QueryException::class);
        DB::table('ados_episodes')->insert([
            'patient_id' => $this->patient->id,
            'branch_id' => $this->branch->id,
            'company_id' => $this->company->id,
            'episode_type_id' => $this->secondType->id,
            'status' => 'active',
            'started_at' => '2026-10-02',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_configured_type_change_keeps_episode_and_closes_old_history(): void
    {
        $service = new AdosEpisodeService(new PatientPolicy);
        $config = new AdosEpisodeConfigurationService;
        $config->createTransition($this->company, $this->firstType, $this->secondType);
        $episode = $service->startEpisode(
            $this->patient,
            $this->firstType,
            Carbon::parse('2026-10-01'),
            $this->nurse,
        );

        $changed = $service->changeType(
            $episode,
            $this->secondType,
            Carbon::parse('2026-10-15'),
            $this->nurse,
        );

        self::assertSame($episode->id, $changed->id);
        self::assertSame($this->secondType->id, $changed->episode_type_id);
        self::assertSame(2, $changed->typeHistory()->count());
        self::assertSame('2026-10-15', $changed->typeHistory()->orderBy('id')->first()->valid_to->toDateString());
        self::assertSame('2026-10-15', $changed->typeHistory()->orderBy('id')->get()->last()->valid_from->toDateString());
        self::assertSame('2026-10-31', $changed->expected_end_at->toDateString());
    }

    public function test_type_change_requires_configured_transition_and_active_destination(): void
    {
        $service = new AdosEpisodeService(new PatientPolicy);
        $episode = $service->startEpisode(
            $this->patient,
            $this->firstType,
            Carbon::parse('2026-10-01'),
            $this->nurse,
        );

        try {
            $service->changeType($episode, $this->secondType, Carbon::parse('2026-10-15'), $this->nurse);
            self::fail('An unconfigured transition should be rejected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('episode_type_id', $exception->errors());
        }

        $this->secondType->update(['is_active' => false]);
        (new AdosEpisodeConfigurationService)->createTransition($this->company, $this->firstType, $this->secondType);
        try {
            $service->changeType($episode, $this->secondType, Carbon::parse('2026-10-15'), $this->nurse);
            self::fail('An inactive destination type should be rejected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('episode_type_id', $exception->errors());
        }
    }

    public function test_inactive_type_cannot_start_an_episode(): void
    {
        $this->firstType->update(['is_active' => false]);
        try {
            (new AdosEpisodeService(new PatientPolicy))->startEpisode(
                $this->patient,
                $this->firstType,
                Carbon::parse('2026-10-01'),
                $this->nurse,
            );
            self::fail('An inactive type should be rejected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('episode_type_id', $exception->errors());
        }
    }

    public function test_closing_episode_closes_history_and_access_service_reports_active_episode(): void
    {
        $episodes = new AdosEpisodeService(new PatientPolicy);
        $episode = $episodes->startEpisode(
            $this->patient,
            $this->firstType,
            Carbon::parse('2026-10-01'),
            $this->nurse,
        );

        $access = new AdosEpisodeAccessService(new PatientPolicy);
        self::assertSame($episode->id, $access->requiresActiveEpisode($this->patient, $this->nurse)->id);

        $closed = $episodes->closeEpisode($episode, Carbon::parse('2026-10-30'), $this->nurse);
        self::assertSame('closed', $closed->status);
        self::assertSame('2026-10-30', $closed->ended_at->toDateString());
        self::assertSame($this->nurse->id, $closed->ended_by);
        self::assertSame('2026-10-31', $closed->typeHistory()->first()->valid_to->toDateString());
        self::assertNull($access->activeEpisodeFor($this->patient));
    }

    public function test_episode_representation_exposes_expected_end_state_and_configured_transitions(): void
    {
        Carbon::setTestNow('2026-10-30 12:00:00');
        (new AdosEpisodeConfigurationService)->createTransition($this->company, $this->firstType, $this->secondType);
        $episode = (new AdosEpisodeService(new PatientPolicy))->startEpisode(
            $this->patient,
            $this->firstType,
            Carbon::parse('2026-09-01'),
            $this->nurse,
        );
        $episode->load(['type.outgoingTransitions.toType', 'typeHistory.type']);

        $data = (new AdosEpisodeResource($episode))->toArray(Request::create('/'));

        self::assertTrue($data['expected_end_passed']);
        self::assertSame('active', $data['status']);
        self::assertSame('2026-10-01', $data['expected_end_at']);
        self::assertSame($this->secondType->name, $data['available_transitions'][0]['name']);
    }

    public function test_type_change_can_start_on_the_same_day_using_half_open_history(): void
    {
        (new AdosEpisodeConfigurationService)->createTransition($this->company, $this->firstType, $this->secondType);
        $episodes = new AdosEpisodeService(new PatientPolicy);
        $episode = $episodes->startEpisode(
            $this->patient,
            $this->firstType,
            Carbon::parse('2026-10-01'),
            $this->nurse,
        );

        $changed = $episodes->changeType($episode, $this->secondType, Carbon::parse('2026-10-01'), $this->nurse);

        self::assertSame(2, $changed->typeHistory()->count());
        self::assertSame('2026-10-01', $changed->typeHistory()->orderBy('id')->first()->valid_to->toDateString());
        self::assertSame('2026-10-01', $changed->typeHistory()->orderBy('id')->get()->last()->valid_from->toDateString());
    }

    public function test_company_configuration_rejects_cross_company_types_and_duplicate_transitions(): void
    {
        $otherCompany = Company::query()->create(['name' => 'Other company']);
        $otherType = $this->createType('Other type', 'other_type', null, $otherCompany);
        $config = new AdosEpisodeConfigurationService;

        try {
            $config->createTransition($this->company, $this->firstType, $otherType);
            self::fail('A cross-company transition should be rejected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('episode_type_id', $exception->errors());
        }

        $config->createTransition($this->company, $this->firstType, $this->secondType);
        try {
            $config->createTransition($this->company, $this->firstType, $this->secondType);
            self::fail('A duplicate transition should be rejected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('to_episode_type_id', $exception->errors());
        }

        try {
            (new AdosEpisodeService(new PatientPolicy))->startEpisode(
                $this->patient,
                $otherType,
                Carbon::parse('2026-10-01'),
                $this->nurse,
            );
            self::fail('A cross-company type should not be usable for the patient.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('episode_type_id', $exception->errors());
        }
    }

    public function test_default_configuration_seeder_is_company_scoped_and_idempotent(): void
    {
        $seeder = new AdosEpisodeConfigurationSeeder;
        $seeder->run();
        $seeder->run();

        self::assertSame(5, $this->company->episodeTypes()->count());
        self::assertSame(3, $this->company->episodeTypeTransitions()->count());
        self::assertSame(5, $this->company->episodeTypes()->where('is_active', true)->count());
    }

    public function test_configuration_access_is_company_scoped_and_nurses_cannot_manage_types(): void
    {
        $managerRole = Role::query()->create(['position' => 'manager', 'scope' => 'company']);
        $manager = User::query()->create([
            'company_id' => $this->company->id,
            'role_id' => $managerRole->id,
            'email' => 'manager@example.test',
        ]);
        $otherCompany = Company::query()->create(['name' => 'Other company']);
        $configuration = new AdosEpisodeConfigurationService;

        $configuration->authorizeCompany($manager, $this->company);
        try {
            $configuration->authorizeCompany($manager, $otherCompany);
            self::fail('A manager must not configure another company.');
        } catch (AuthorizationException $exception) {
            self::assertSame('Nemáte oprávnenie pristupovať k nastaveniam spoločnosti.', $exception->getMessage());
        }

        $configuration->authorizeCompanyAccess($this->nurse, $this->company);
        try {
            $configuration->authorizeCompany($this->nurse, $this->company);
            self::fail('A nurse must not manage episode types.');
        } catch (AuthorizationException $exception) {
            self::assertSame('Nemáte oprávnenie spravovať nastavenia spoločnosti.', $exception->getMessage());
        }
    }

    public function test_patient_company_change_hides_and_blocks_old_company_episodes(): void
    {
        $episode = (new AdosEpisodeService(new PatientPolicy))->startEpisode(
            $this->patient,
            $this->firstType,
            Carbon::parse('2026-10-01'),
            $this->nurse,
        );
        $otherCompany = Company::query()->create(['name' => 'Other company']);
        $otherBranch = Branch::query()->create(['company_id' => $otherCompany->id]);
        $this->patient->update(['branch_id' => $otherBranch->id]);
        $patient = $this->patient->fresh();

        self::assertSame(0, $patient->adosEpisodes()->count());
        self::assertNull($patient->activeAdosEpisode()->first());

        $managerRole = Role::query()->create(['position' => 'manager', 'scope' => 'company']);
        $otherManager = User::query()->create([
            'company_id' => $otherCompany->id,
            'role_id' => $managerRole->id,
            'email' => 'other-manager@example.test',
        ]);
        try {
            (new AdosEpisodeService(new PatientPolicy))->closeEpisode(
                $episode,
                Carbon::parse('2026-10-30'),
                $otherManager,
            );
            self::fail('A new company manager must not manipulate an old-company episode.');
        } catch (ModelNotFoundException $exception) {
            self::assertSame(AdosEpisode::class, $exception->getModel());
        }
    }

    private function createType(string $name, string $code, ?int $duration = null, ?Company $company = null): AdosEpisodeType
    {
        return ($company ?? $this->company)->episodeTypes()->create([
            'name' => $name,
            'code' => $code,
            'default_duration_days' => $duration,
            'is_active' => true,
        ]);
    }

    private function createBaseTables(): void
    {
        Schema::create('company', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('branches', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('company_id')->nullable();
            $table->timestamps();
        });
        Schema::create('roles', function (Blueprint $table) {
            $table->increments('id');
            $table->string('position');
            $table->string('scope')->nullable();
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedInteger('role_id')->nullable();
            $table->string('email')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('user_branches', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->unsignedInteger('branch_id');
            $table->unsignedInteger('role_id')->nullable();
        });
        Schema::create('patients', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('branch_id')->nullable();
            $table->unsignedBigInteger('nurse_id')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }
}
