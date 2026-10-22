<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DocumentationAudit\ImmediateDocumentationAuditService;
use App\Services\LeaveDocumentService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

class LeaveDocumentDraftPreviewTest extends TestCase
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

        Schema::create('patients', function ($table): void {
            $table->id();
            $table->string('title')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('personal_number')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        DB::table('patients')->insert([
            'id' => 10,
            'title' => 'Mgr.',
            'first_name' => 'Jana',
            'last_name' => 'Nováková',
            'personal_number' => '9001011234',
            'created_at' => '2026-01-01 08:00:00',
            'updated_at' => '2026-01-01 08:00:00',
        ]);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        parent::tearDown();
    }

    public function test_preview_builds_payload_without_persisting_or_running_audit(): void
    {
        $audit = $this->createMock(ImmediateDocumentationAuditService::class);
        $audit->expects(self::never())->method('documentsChanged');

        $user = new User();
        $user->forceFill([
            'id' => 20,
            'company_id' => 7,
            'title' => 'Bc.',
            'first_name' => 'Eva',
            'last_name' => 'Sestra',
        ]);

        $payload = (new LeaveDocumentService($audit))->preview([
            'patient_id' => 10,
            'date' => '2026-10-22 10:30:00',
            'problems' => ['mobility'],
            'results' => 'Ukončená ošetrovateľská starostlivosť',
        ], $user);

        self::assertNull($payload['document_id']);
        self::assertSame('Mgr. Jana Nováková', $payload['patient_name']);
        self::assertSame('9001011234', $payload['patient_birth_number']);
        self::assertSame('Bc. Eva Sestra', $payload['user_name']);
        self::assertSame(['mobility'], $payload['problems']);
    }
}
