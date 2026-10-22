<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\DocumentationAudit\ImmediateDocumentationAuditService;
use App\Services\RecordDocumentService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

class RecordDocumentDraftPreviewTest extends TestCase
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
            $table->string('contact')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('zip')->nullable();
            $table->unsignedBigInteger('doctor_id')->nullable();
            $table->unsignedBigInteger('insurance_company_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        DB::table('patients')->insert([
            'id' => 10,
            'title' => 'Mgr.',
            'first_name' => 'Jana',
            'last_name' => 'Nováková',
            'personal_number' => '9001011234',
            'contact' => 'jana@example.test',
            'address' => 'Hlavná 1',
            'city' => 'Trnava',
            'zip' => '91701',
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

    public function test_preview_builds_record_payload_without_persisting_or_running_audit(): void
    {
        $audit = $this->createMock(ImmediateDocumentationAuditService::class);
        $audit->expects(self::never())->method('documentsChanged');

        $company = new Company();
        $company->forceFill([
            'id' => 7,
            'name' => 'ADOS Test',
            'address' => 'Firemná 2',
            'city' => 'Trnava',
            'psc' => '91701',
        ]);

        $user = new User();
        $user->forceFill([
            'id' => 20,
            'company_id' => 7,
            'title' => 'Bc.',
            'first_name' => 'Eva',
            'last_name' => 'Sestra',
        ]);
        $user->setRelation('company', $company);

        $payload = (new RecordDocumentService($audit))->preview([
            'patient_id' => 10,
            'date' => '2026-10-22',
            'form_spec' => ['sections' => []],
            'record_data' => [
                'diagnosis' => [],
                'nursingDiagnoses.list' => [],
                'admissionDate' => '2026-10-22',
            ],
        ], $user);

        self::assertNull($payload['document_id']);
        self::assertSame('ADOS Test', $payload['company_name']);
        self::assertSame('Mgr. Jana Nováková', trim($payload['patient_name']));
        self::assertSame('Bc. Eva Sestra', $payload['user_name']);
        self::assertSame([], $payload['form_data']['diagnosis']);
        self::assertSame([], $payload['form_data']['nursingDiagnoses.list']);
    }
}
