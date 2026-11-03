<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Services\DocumentationAudit\ImmediateDocumentationAuditService;
use App\Services\LeaveDocumentService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\TestCase;

class LeaveDocumentDraftPreviewTest extends TestCase
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

        Schema::create('patients', function ($table): void {
            $table->id();
            $table->string('title')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('personal_number')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('documents', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('type');
            $table->string('mime_type');
            $table->string('name');
            $table->string('path');
            $table->string('period')->nullable();
            $table->date('reference_date')->nullable();
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

        $user = new User;
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

    public function test_update_replaces_existing_leave_payload_without_creating_a_document(): void
    {
        $audit = $this->createMock(ImmediateDocumentationAuditService::class);
        $audit->expects(self::once())->method('documentsChanged');

        $user = new User;
        $user->forceFill([
            'id' => 20,
            'company_id' => 7,
            'title' => 'Bc.',
            'first_name' => 'Eva',
            'last_name' => 'Sestra',
        ]);

        $document = Document::query()->create([
            'patient_id' => 10,
            'user_id' => 20,
            'branch_id' => 3,
            'type' => 'leave',
            'mime_type' => 'application/json',
            'name' => 'leave.json',
            'path' => 'documents/leave/1.json',
            'period' => '2026-09',
        ]);

        $disk = \Mockery::mock();
        $disk->shouldReceive('exists')
            ->once()
            ->with($document->path)
            ->andReturnTrue();
        $disk->shouldReceive('get')
            ->once()
            ->with($document->path)
            ->andReturn(json_encode([
                'document_id' => $document->id,
                'user_name' => 'Pôvodný používateľ',
                'results' => 'Pôvodný výsledok',
            ]));
        $disk->shouldReceive('put')
            ->once()
            ->with($document->path, \Mockery::on(function (string $json): bool {
                $payload = json_decode($json, true);

                return $payload['results'] === 'Aktualizovaný výsledok'
                    && $payload['document_id'] === 1
                    && $payload['user_name'] === 'Pôvodný používateľ';
            }))
            ->andReturnTrue();
        $storage = \Mockery::mock();
        $storage->shouldReceive('disk')->times(3)->with('local')->andReturn($disk);
        Storage::swap($storage);

        $updated = (new LeaveDocumentService($audit))->update($document, [
            'patient_id' => 10,
            'branch_id' => 4,
            'date' => '2026-10-22',
            'results' => 'Aktualizovaný výsledok',
        ], $user);

        self::assertSame($document->id, $updated->id);
        self::assertSame(1, Document::query()->count());
        self::assertSame('2026-10', $updated->fresh()->period);
        self::assertSame('2026-10-22', $updated->fresh()->reference_date->toDateString());
        self::assertSame(3, $updated->fresh()->branch_id);
    }

    public function test_legacy_document_reference_date_is_backfilled_from_its_exact_payload_date(): void
    {
        $document = Document::query()->create([
            'patient_id' => 10,
            'user_id' => 20,
            'branch_id' => 3,
            'type' => 'leave',
            'mime_type' => 'application/json',
            'name' => 'leave.json',
            'path' => 'leave/legacy.json',
            'period' => '2026-10',
            'created_at' => '2026-10-01 08:00:00',
            'updated_at' => '2026-10-01 08:00:00',
        ]);
        DB::table('documents')->where('id', $document->id)->update([
            'created_at' => '2026-10-01 08:00:00',
            'updated_at' => '2026-10-01 08:00:00',
        ]);
        $disk = \Mockery::mock();
        $disk->shouldReceive('exists')->once()->with($document->path)->andReturnTrue();
        $disk->shouldReceive('get')->once()->with($document->path)->andReturn(json_encode([
            'document_id' => $document->id,
            'date' => '2026-10-22 10:30:00',
        ]));
        $storage = \Mockery::mock();
        $storage->shouldReceive('disk')->once()->with('local')->andReturn($disk);
        Storage::swap($storage);

        $migration = require __DIR__.'/../../database/migrations/2026_11_03_211000_backfill_patient_document_reference_dates.php';
        $migration->up();

        $document->refresh();
        self::assertSame('2026-10-22', $document->reference_date->toDateString());
        self::assertSame('2026-10-01 08:00:00', $document->updated_at->format('Y-m-d H:i:s'));
    }
}
