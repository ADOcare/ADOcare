<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Patient;
use App\Services\PatientNotificationService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

class PatientNotificationServiceTest extends TestCase
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
        $this->seedRecipients();
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        parent::tearDown();
    }

    public function test_events_are_sent_once_to_assigned_nurse_and_company_managers(): void
    {
        $patient = Patient::query()->findOrFail(1);
        $service = new PatientNotificationService();

        $service->createEvent($patient, 'patient:1:dead', 'patient_dead', 'Dead', 'Description');
        $service->createEvent($patient, 'patient:1:dead', 'patient_dead', 'Dead', 'Description');

        self::assertSame(2, AppNotification::query()->count());
        self::assertSame([1, 2], AppNotification::query()->orderBy('user_id')->pluck('user_id')->all());

        $notification = AppNotification::query()->where('user_id', 1)->firstOrFail();
        $notification->update(['dismissed_at' => now()]);

        $service->createEvent($patient, 'patient:1:dead', 'patient_dead', 'Dead', 'Description');

        self::assertNotNull($notification->fresh()->dismissed_at);
    }

    public function test_persisting_issues_reopen_and_resolved_issues_are_closed(): void
    {
        $patient = Patient::query()->findOrFail(1);
        $service = new PatientNotificationService();
        $key = 'patient:1:insurance';

        $service->reportIssue($patient, $key, 'patient_insurance_issue', 'Insurance', 'Description');

        AppNotification::query()->where('user_id', 1)->update(['dismissed_at' => now()]);
        $service->reportIssue($patient, $key, 'patient_insurance_issue', 'Insurance', 'Still present');

        self::assertNull(AppNotification::query()->where('user_id', 1)->value('dismissed_at'));
        self::assertSame('Still present', AppNotification::query()->where('user_id', 1)->value('description'));

        $service->resolveIssue($patient, $key);

        self::assertSame(0, AppNotification::query()->whereNull('resolved_at')->count());
    }

    private function seedRecipients(): void
    {
        DB::table('roles')->insert([
            ['id' => 1, 'position' => 'manager'],
            ['id' => 2, 'position' => 'nurse'],
        ]);
        DB::table('branches')->insert([
            'id' => 1,
            'company_id' => 10,
        ]);
        DB::table('users')->insert([
            ['id' => 1, 'company_id' => 10, 'role_id' => 2],
            ['id' => 2, 'company_id' => 10, 'role_id' => 1],
            ['id' => 3, 'company_id' => 20, 'role_id' => 1],
        ]);
        DB::table('patients')->insert([
            'id' => 1,
            'first_name' => 'Anna',
            'last_name' => 'Patient',
            'branch_id' => 1,
            'nurse_id' => 1,
        ]);
    }

    private function createTables(): void
    {
        Schema::create('roles', function ($table) {
            $table->id();
            $table->string('position');
        });
        Schema::create('branches', function ($table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
        });
        Schema::create('users', function ($table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('role_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
        Schema::create('patients', function ($table) {
            $table->id();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('nurse_id')->nullable();
            $table->date('death_date')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
        Schema::create('app_notifications', function ($table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('key');
            $table->string('type');
            $table->string('kind');
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'key']);
        });
    }
}
