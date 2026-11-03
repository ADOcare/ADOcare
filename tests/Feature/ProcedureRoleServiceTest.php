<?php

namespace Tests\Feature;

use App\Models\Procedure;
use App\Models\User;
use App\Services\ProcedureRoleService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

class ProcedureRoleServiceTest extends TestCase
{
    private ProcedureRoleService $service;

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
        $app->instance('validator', new Factory(new Translator(new ArrayLoader, 'sk'), $app));

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        $this->createTables();
        $this->seedData();
        $this->service = new ProcedureRoleService;
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        parent::tearDown();
    }

    public function test_procedures_are_scoped_to_the_users_role_in_the_selected_branch(): void
    {
        $user = $this->clinicalUser();

        $nurseCodes = $this->service
            ->scopeForUser(Procedure::query(), $user, 10)
            ->orderBy('code')
            ->pluck('code')
            ->all();

        self::assertSame(['BOTH', 'NURSE'], $nurseCodes);

        app('db')->table('user_branches')
            ->where('user_id', 100)
            ->where('branch_id', 10)
            ->update(['role_id' => 2]);

        $physiotherapistCodes = $this->service
            ->scopeForUser(Procedure::query(), $user, 10)
            ->orderBy('code')
            ->pluck('code')
            ->all();

        self::assertSame(['BOTH', 'PHYSIO'], $physiotherapistCodes);
    }

    public function test_disallowed_procedure_assignment_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->ensureAllowed($this->clinicalUser(), 2, 10);
    }

    public function test_legacy_branch_assignments_without_role_keep_nurse_access(): void
    {
        app('db')->table('user_branches')
            ->where('user_id', 100)
            ->where('branch_id', 10)
            ->update(['role_id' => null]);

        $codes = $this->service
            ->scopeForUser(Procedure::query(), $this->clinicalUser(), 10)
            ->orderBy('code')
            ->pluck('code')
            ->all();

        self::assertSame(['BOTH', 'NURSE'], $codes);
    }

    public function test_unassigned_branch_does_not_fall_back_to_nurse_access(): void
    {
        $codes = $this->service
            ->scopeForUser(Procedure::query(), $this->clinicalUser(), 99)
            ->pluck('code')
            ->all();

        self::assertSame([], $codes);
    }

    public function test_managers_can_use_all_procedures(): void
    {
        $manager = new User;
        $manager->id = 200;
        $manager->role_id = 3;

        $codes = $this->service
            ->scopeForUser(Procedure::query(), $manager, null)
            ->orderBy('code')
            ->pluck('code')
            ->all();

        self::assertSame(['BOTH', 'NURSE', 'PHYSIO'], $codes);
        $this->service->ensureAllowed($manager, 2, 10);
    }

    private function clinicalUser(): User
    {
        $user = new User;
        $user->id = 100;
        $user->role_id = null;

        return $user;
    }

    private function createTables(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->increments('id');
            $table->string('position');
            $table->string('scope');
            $table->timestamps();
        });
        Schema::create('procedures', function (Blueprint $table) {
            $table->increments('id');
            $table->string('code');
            $table->string('description');
            $table->timestamps();
        });
        Schema::create('procedure_role', function (Blueprint $table) {
            $table->unsignedInteger('procedure_id');
            $table->unsignedInteger('role_id');
        });
        Schema::create('user_branches', function (Blueprint $table) {
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('branch_id');
            $table->unsignedInteger('role_id')->nullable();
        });
    }

    private function seedData(): void
    {
        app('db')->table('roles')->insert([
            ['id' => 1, 'position' => 'nurse', 'scope' => 'branch'],
            ['id' => 2, 'position' => 'physiotherapist', 'scope' => 'branch'],
            ['id' => 3, 'position' => 'manager', 'scope' => 'company'],
        ]);
        app('db')->table('procedures')->insert([
            ['id' => 1, 'code' => 'NURSE', 'description' => 'Nurse procedure'],
            ['id' => 2, 'code' => 'PHYSIO', 'description' => 'Physiotherapy procedure'],
            ['id' => 3, 'code' => 'BOTH', 'description' => 'Shared procedure'],
        ]);
        app('db')->table('procedure_role')->insert([
            ['procedure_id' => 1, 'role_id' => 1],
            ['procedure_id' => 2, 'role_id' => 2],
            ['procedure_id' => 3, 'role_id' => 1],
            ['procedure_id' => 3, 'role_id' => 2],
        ]);
        app('db')->table('user_branches')->insert([
            'user_id' => 100,
            'branch_id' => 10,
            'role_id' => 1,
        ]);
    }
}
