<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Patient;
use App\Models\PatientPoint;
use App\Models\PatientProcedureDekurzTemplate;
use App\Models\Procedure;
use App\Models\User;
use App\Policies\PatientPolicy;
use App\Services\DekurzBuilderService;
use App\Services\DekurzDraftInitializer;
use App\Services\DekurzDocumentService;
use App\Services\DekurzEntryBatchService;
use App\Services\DekurzFinalizationValidator;
use App\Services\DekurzProcedureClassifier;
use App\Services\DekurzScoringConsistencyService;
use App\Services\DekurzScoringService;
use App\Services\DekurzSupportingDocumentService;
use App\Services\DekurzTextResolver;
use App\Services\DekurzV2Finalizer;
use App\Services\DekurzV2SnapshotBuilder;
use App\Services\DocumentService;
use App\Services\PatientProcedureDekurzTemplateService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidationFactory;
use PHPUnit\Framework\TestCase;

class DekurzV2BuilderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        Container::setInstance($app);
        $app->instance('config', new Repository([
            'dekurz' => require __DIR__.'/../../config/dekurz.php',
        ]));

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
        $app->singleton('validator', fn ($app) => new ValidationFactory(
            new Translator(new ArrayLoader(), 'sk'),
            $app,
        ));

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        DB::statement('PRAGMA foreign_keys = ON');

        $this->createExistingTables();
        (require __DIR__.'/../../database/migrations/2026_10_15_000000_create_dekurz_v2_foundation_tables.php')->up();
        (require __DIR__.'/../../database/migrations/2026_10_15_010000_add_dekurz_builder_fields.php')->up();
        $this->seedRecords();
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        parent::tearDown();
    }

    public function test_builder_aggregates_documentable_rows_and_generates_chronological_days_without_visit_codes(): void
    {
        $result = $this->builder()->build(Patient::findOrFail(1), '2026-10');

        self::assertSame(['3416', '3418', '3423B'], array_column($result['procedures'], 'code'));
        $medication = collect($result['procedures'])->firstWhere('code', '3416');
        self::assertSame(5, $medication['total_quantity']);
        self::assertSame(2, $medication['scoring_rows_count']);
        self::assertSame([100, 101], array_column($medication['scoring_rows'], 'id'));
        self::assertSame(['2026-10-03'], $medication['dates']);
        self::assertSame(['2026-10-01', '2026-10-03', '2026-10-05'], array_column($result['days'], 'date'));
        self::assertNotContains('2026-10-02', array_column($result['days'], 'date'));
    }

    public function test_template_resolution_uses_patient_then_procedure_default_then_empty(): void
    {
        PatientProcedureDekurzTemplate::create([
            'patient_id' => 1,
            'procedure_id' => 10,
            'text' => 'Text pacienta pre 3416.',
        ]);

        $procedures = collect($this->builder()->build(Patient::findOrFail(1), '2026-10')['procedures'])->keyBy('code');

        self::assertSame(['text' => 'Text pacienta pre 3416.', 'source' => 'patient_template'], $procedures['3416']['template']);
        self::assertSame(['text' => 'Predvolený text pre ranu.', 'source' => 'procedure_default'], $procedures['3423B']['template']);
        self::assertSame(['text' => '', 'source' => 'empty'], $procedures['3418']['template']);
    }

    public function test_draft_initialization_is_idempotent_and_does_not_overwrite_saved_text(): void
    {
        $patient = Patient::findOrFail(1);
        $actor = User::findOrFail(1);
        $initializer = $this->initializer();

        $draft = $initializer->initialize($patient, '2026-10', $actor);
        self::assertCount(3, $draft->days);
        self::assertSame(4, $draft->days->sum(fn ($day): int => $day->entries->count()));
        $entry = $draft->days->flatMap->entries->firstWhere('patient_point_snapshot_id', 100);
        self::assertSame(100, $entry->patient_point_id);
        self::assertSame(2, $entry->quantity);

        $entry->update([
            'text' => 'Individuálne upravený denný text.',
            'source' => 'manual',
            'edited_at' => now(),
        ]);
        Procedure::findOrFail(10)->update(['default_dekurz_text' => 'Nový predvolený text.']);

        $reopened = $initializer->initialize($patient, '2026-10', $actor);
        self::assertSame(3, $reopened->days->count());
        self::assertSame(4, $reopened->days->sum(fn ($day): int => $day->entries->count()));
        self::assertSame('Individuálne upravený denný text.', $entry->fresh()->text);

        $days = collect($this->builder()->build($patient, '2026-10')['days']);
        $saved = collect($days->firstWhere('date', '2026-10-03')['entries'])->firstWhere('patient_point_id', 100);
        self::assertSame('saved_draft', $saved['source']);
        self::assertSame('Individuálne upravený denný text.', $saved['text']);
    }

    public function test_reconciliation_detects_added_removed_and_changed_scoring_rows(): void
    {
        $patient = Patient::findOrFail(1);
        $this->initializer()->initialize($patient, '2026-10', User::findOrFail(1));

        PatientPoint::create($this->pointAttributes(105, '3416', 10, '2026-10-10', 1));
        PatientPoint::findOrFail(100)->update(['quantity' => 9]);
        PatientPoint::findOrFail(101)->delete();

        $changes = $this->builder()->build($patient, '2026-10')['scoring_changes'];

        self::assertSame([105], array_column($changes['added'], 'id'));
        self::assertSame([101], array_column($changes['removed'], 'patient_point_id'));
        self::assertSame(100, $changes['changed'][0]['patient_point_id']);
        self::assertSame(['draft' => 2, 'current' => 9], $changes['changed'][0]['differences']['quantity']);
    }

    public function test_supporting_documents_use_reference_dates_month_relevance_and_strict_previous_dekurz(): void
    {
        $this->storePayloads([
            'scans/exact.json' => ['scanned_at' => '2026-09-15T09:00:00+00:00'],
            'scans/october.json' => ['scanned_at' => '2026-10-02T09:00:00+00:00'],
            'proposals/september.json' => ['date' => '2026-09-01'],
            'proposals/october.json' => ['date' => '2026-10-04'],
        ]);
        $this->createDocument(200, 'scan', '2026-09', 'scans/exact.json', '2026-09-15');
        $this->createDocument(201, 'scan', '2026-10', 'scans/october.json', '2026-10-02');
        $this->createDocument(210, 'proposal', '2026-09', 'proposals/september.json', '2026-09-01');
        $this->createDocument(211, 'proposal', '2026-10', 'proposals/october.json', '2026-10-04');
        $this->createDocument(220, 'dekurz', '2026-08', 'dekurz/august.json', '2026-08-31');
        $this->createDocument(221, 'dekurz', '2026-09', 'dekurz/september.json', '2026-09-30');
        $this->createDocument(222, 'dekurz', '2026-10', 'dekurz/october.json', '2026-10-31');

        $documents = $this->builder()->build(Patient::findOrFail(1), '2026-10')['supporting_documents'];

        self::assertSame('2026-09-15', $documents['finding']['dominant_reference_date']);
        self::assertSame(200, $documents['finding']['default']['id']);
        self::assertSame('exact_reference_date', $documents['finding']['default']['match']);
        self::assertSame('/v1/scan/200/preview', $documents['finding']['default']['preview_url']);
        self::assertSame(211, $documents['proposal']['default']['id']);
        self::assertSame('requested_month', $documents['proposal']['default']['match']);
        self::assertSame(221, $documents['previous_dekurz']['id']);
        self::assertSame('latest_strictly_before_month', $documents['previous_dekurz']['match']);
    }

    public function test_template_upsert_and_reset_are_independent_from_daily_entries(): void
    {
        $service = new PatientProcedureDekurzTemplateService(new DekurzProcedureClassifier());
        $patient = Patient::findOrFail(1);
        $procedure = Procedure::findOrFail(10);
        $actor = User::findOrFail(1);

        $first = $service->upsert($patient, $procedure, 'Prvý text.', $actor);
        $second = $service->upsert($patient, $procedure, 'Aktualizovaný text.', $actor);

        self::assertSame($first->id, $second->id);
        self::assertSame('Aktualizovaný text.', $second->text);
        self::assertSame(1, PatientProcedureDekurzTemplate::count());
        self::assertTrue($service->delete($patient, $procedure));
        self::assertSame(0, PatientProcedureDekurzTemplate::count());
    }

    public function test_daily_text_is_applied_only_to_unedited_entries_for_the_same_procedure(): void
    {
        PatientPoint::create($this->pointAttributes(105, '3416', 10, '2026-10-10', 1));
        $draft = $this->initializer()->initialize(Patient::findOrFail(1), '2026-10', User::findOrFail(1));
        $entries = $draft->days->flatMap->entries->keyBy('patient_point_snapshot_id');
        $source = $entries->get(100);
        $protected = $entries->get(101);
        $target = $entries->get(105);
        $source->update(['text' => 'Text pre ďalšie dni.', 'source' => 'manual', 'edited_at' => now()]);
        $protected->update(['text' => 'Už upravený text.', 'source' => 'manual', 'edited_at' => now()]);

        $result = (new DekurzEntryBatchService())->applyToUnedited($draft, $source);

        self::assertSame(1, $result['updated_count']);
        self::assertSame([$target->id], $result['updated_entry_ids']);
        self::assertSame('Už upravený text.', $protected->fresh()->text);
        self::assertSame('Text pre ďalšie dni.', $target->fresh()->text);
        self::assertNotNull($target->fresh()->edited_at);
    }

    public function test_finalization_validation_accepts_a_complete_consistent_draft(): void
    {
        $patient = Patient::findOrFail(1);
        $actor = User::findOrFail(1);
        $draft = $this->initializer()->initialize($patient, '2026-10', $actor);
        $draft->days->flatMap->entries->each(fn ($entry) => $entry->update(['text' => 'Finálny text.']));

        $points = $this->finalizationValidator()->validate($patient, $draft->fresh(), $actor);

        self::assertCount(4, $points);
    }

    public function test_finalization_validation_returns_structured_errors_for_reconciliation_changes(): void
    {
        $patient = Patient::findOrFail(1);
        $actor = User::findOrFail(1);
        $draft = $this->initializer()->initialize($patient, '2026-10', $actor);
        $draft->days->flatMap->entries->each(fn ($entry) => $entry->update(['text' => 'Finálny text.']));
        $entry = $draft->days->flatMap->entries->firstWhere('patient_point_snapshot_id', 100);
        PatientPoint::findOrFail(100)->update(['quantity' => 9]);

        try {
            $this->finalizationValidator()->validate($patient, $draft->fresh(), $actor);
            self::fail('ValidationException was not thrown.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $key = "entries.{$entry->id}";
            self::assertArrayHasKey($key, $exception->errors());
            self::assertStringContainsString('Množstvo', $exception->errors()[$key][0]);
        }
    }

    public function test_finalization_creates_an_immutable_v2_snapshot_and_completes_the_draft(): void
    {
        $patient = Patient::findOrFail(1);
        $actor = User::findOrFail(1);
        $draft = $this->initializer()->initialize($patient, '2026-10', $actor);
        $draft->days->flatMap->entries->each(fn ($entry) => $entry->update(['text' => 'Text pri finalizácii.']));
        $storage = $this->useInMemoryStorage();
        $documentService = $this->createMock(DocumentService::class);
        $documentService->expects(self::once())
            ->method('getTravelDocumentPdfPath')
            ->willReturnCallback(function (Document $document) use ($storage): string {
                $path = "documents/pdf/dekurz/{$document->id}.pdf";
                $storage->put($path, 'PDF');

                return $path;
            });

        $document = $this->finalizer($documentService)->finalize($patient, $draft, $actor);
        $payload = json_decode($storage->get($document->path), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(2, $payload['schema_version']);
        self::assertSame('dekurz_v2', $payload['source']);
        self::assertSame($draft->id, $payload['draft_id']);
        self::assertSame('Text pri finalizácii.', $payload['days'][0]['entries'][0]['text']);
        self::assertSame('completed', $draft->fresh()->status);
        self::assertSame($document->id, $draft->fresh()->final_document_id);
        self::assertSame('8', $patient->fresh()->dekurz_number);
        self::assertTrue($storage->exists("documents/pdf/dekurz/{$document->id}.pdf"));

        PatientPoint::findOrFail(102)->update(['quantity' => 9]);
        Procedure::findOrFail(11)->update(['default_dekurz_text' => 'Zmenená šablóna.']);
        $draft->days->flatMap->entries->first()->update(['text' => 'Neskoršia zmena.']);

        self::assertSame($payload, json_decode($storage->get($document->path), true, flags: JSON_THROW_ON_ERROR));
        self::assertSame($payload, (new DekurzDocumentService())->findDekurzFileForDocument($document));
    }

    public function test_finalization_rolls_back_database_and_snapshot_when_pdf_generation_fails(): void
    {
        $patient = Patient::findOrFail(1);
        $actor = User::findOrFail(1);
        $draft = $this->initializer()->initialize($patient, '2026-10', $actor);
        $draft->days->flatMap->entries->each(fn ($entry) => $entry->update(['text' => 'Finálny text.']));
        $storage = $this->useInMemoryStorage();
        $documentService = $this->createMock(DocumentService::class);
        $documentService->expects(self::once())->method('getTravelDocumentPdfPath')->willReturn(null);

        try {
            $this->finalizer($documentService)->finalize($patient, $draft, $actor);
            self::fail('RuntimeException was not thrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Nepodarilo sa vygenerovať PDF Dekurzu.', $exception->getMessage());
        }

        self::assertSame(0, Document::count());
        self::assertSame('draft', $draft->fresh()->status);
        self::assertNull($draft->fresh()->final_document_id);
        self::assertSame('7', $patient->fresh()->dekurz_number);
        self::assertSame([], $storage->files);
    }

    public function test_inaccessible_patient_is_rejected_for_read_and_draft_initialization(): void
    {
        $patient = Patient::findOrFail(1);
        $outsider = User::findOrFail(2);

        self::assertFalse((new PatientPolicy())->view($outsider, $patient));
        self::assertFalse((new PatientPolicy())->update($outsider, $patient));

        $this->expectException(AuthorizationException::class);
        $this->initializer()->initialize($patient, '2026-10', $outsider);
    }

    private function builder(): DekurzBuilderService
    {
        $classifier = new DekurzProcedureClassifier();

        return new DekurzBuilderService(
            new DekurzScoringService($classifier),
            $classifier,
            new DekurzTextResolver(),
            new DekurzSupportingDocumentService(),
        );
    }

    private function initializer(): DekurzDraftInitializer
    {
        $classifier = new DekurzProcedureClassifier();

        return new DekurzDraftInitializer(
            new DekurzScoringService($classifier),
            new DekurzTextResolver(),
            new DekurzScoringConsistencyService(),
        );
    }

    private function finalizationValidator(): DekurzFinalizationValidator
    {
        $classifier = new DekurzProcedureClassifier();

        return new DekurzFinalizationValidator(
            new DekurzScoringService($classifier),
            new DekurzScoringConsistencyService(),
        );
    }

    private function finalizer(DocumentService $documentService): DekurzV2Finalizer
    {
        return new DekurzV2Finalizer(
            $this->finalizationValidator(),
            new DekurzV2SnapshotBuilder(new DekurzDocumentService(), new DekurzSupportingDocumentService()),
            $documentService,
        );
    }

    private function useInMemoryStorage(): object
    {
        $storage = new class {
            /** @var array<string, string> */
            public array $files = [];

            public function disk(string $name): self
            {
                return $this;
            }

            public function put(string $path, string $contents): bool
            {
                $this->files[$path] = $contents;

                return true;
            }

            public function exists(string $path): bool
            {
                return array_key_exists($path, $this->files);
            }

            public function get(string $path): string
            {
                return $this->files[$path];
            }

            public function delete(string $path): bool
            {
                unset($this->files[$path]);

                return true;
            }
        };
        Storage::swap($storage);

        return $storage;
    }

    private function createExistingTables(): void
    {
        Schema::create('roles', function ($table) {
            $table->id();
            $table->string('position');
        });
        Schema::create('company', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('title')->nullable();
            $table->unsignedInteger('company_id')->nullable();
            $table->unsignedBigInteger('role_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('branches', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('company_id')->nullable();
            $table->timestamps();
        });
        Schema::create('user_branches', function ($table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedInteger('branch_id');
            $table->unsignedBigInteger('role_id')->nullable();
            $table->string('working_time')->nullable();
        });
        Schema::create('patients', function ($table) {
            $table->increments('id');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('title')->nullable();
            $table->string('personal_number')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('zip')->nullable();
            $table->string('dekurz_number')->nullable();
            $table->unsignedInteger('insurance_company_id')->nullable();
            $table->unsignedInteger('branch_id')->nullable();
            $table->unsignedBigInteger('nurse_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('procedures', function ($table) {
            $table->increments('id');
            $table->string('code')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('patient_points', function ($table) {
            $table->increments('id');
            $table->date('date')->nullable();
            $table->unsignedInteger('patient_id')->nullable();
            $table->string('procedure_code')->nullable();
            $table->unsignedInteger('procedure_id')->nullable();
            $table->date('reference_date')->nullable();
            $table->unsignedInteger('branch_id')->nullable();
            $table->smallInteger('quantity')->nullable();
            $table->timestamps();
        });
        Schema::create('documents', function ($table) {
            $table->id();
            $table->unsignedInteger('patient_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('type');
            $table->string('mime_type')->nullable();
            $table->string('name')->nullable();
            $table->string('path')->nullable();
            $table->unsignedInteger('branch_id')->nullable();
            $table->unsignedInteger('company_id')->nullable();
            $table->unsignedInteger('insurance_company_id')->nullable();
            $table->string('period')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('insurance_companies', function ($table) {
            $table->increments('id');
            $table->string('branch_code')->nullable();
            $table->timestamps();
        });
        Schema::create('visits', function ($table) {
            $table->id();
            $table->unsignedInteger('patient_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedInteger('branch_id');
            $table->date('date');
            $table->time('terrain_time')->nullable();
            $table->time('administrative_time')->nullable();
        });
    }

    private function seedRecords(): void
    {
        DB::table('roles')->insert([
            ['id' => 1, 'position' => 'manager'],
            ['id' => 2, 'position' => 'nurse'],
        ]);
        DB::table('company')->insert([
            ['id' => 1, 'name' => 'Company One', 'address' => 'Hlavná 1', 'city' => 'Bratislava'],
            ['id' => 2, 'name' => 'Company Two', 'address' => null, 'city' => null],
        ]);
        DB::table('branches')->insert([
            ['id' => 1, 'company_id' => 1],
            ['id' => 2, 'company_id' => 2],
        ]);
        DB::table('users')->insert([
            ['id' => 1, 'first_name' => 'Nurse', 'last_name' => 'One', 'company_id' => 1, 'role_id' => 2],
            ['id' => 2, 'first_name' => 'Nurse', 'last_name' => 'Two', 'company_id' => 2, 'role_id' => 2],
        ]);
        DB::table('user_branches')->insert([
            ['user_id' => 1, 'branch_id' => 1, 'role_id' => 2],
            ['user_id' => 2, 'branch_id' => 2, 'role_id' => 2],
        ]);
        DB::table('patients')->insert([
            ['id' => 1, 'first_name' => 'Anna', 'last_name' => 'Pacientová', 'branch_id' => 1, 'nurse_id' => 1, 'dekurz_number' => '7'],
            ['id' => 2, 'first_name' => 'Iná', 'last_name' => 'Pacientová', 'branch_id' => 2, 'nurse_id' => 2, 'dekurz_number' => '1'],
        ]);
        DB::table('procedures')->insert([
            ['id' => 10, 'code' => '3416', 'description' => 'Podanie lieku', 'default_dekurz_text' => 'Predvolený text pre liek.'],
            ['id' => 11, 'code' => '3423B', 'description' => 'Preväz rany', 'default_dekurz_text' => 'Predvolený text pre ranu.'],
            ['id' => 12, 'code' => '3439', 'description' => 'Návšteva', 'default_dekurz_text' => null],
            ['id' => 13, 'code' => '3418', 'description' => 'IV liek', 'default_dekurz_text' => null],
        ]);
        DB::table('patient_points')->insert([
            $this->pointAttributes(100, '3416', 10, '2026-10-03', 2),
            $this->pointAttributes(101, '3416', 10, '2026-10-03', 3),
            $this->pointAttributes(102, '3423B', 11, '2026-10-01', 1),
            $this->pointAttributes(103, '3439', 12, '2026-10-02', 1),
            $this->pointAttributes(104, '3418', 13, '2026-10-05', 1),
        ]);
    }

    private function pointAttributes(int $id, string $code, int $procedureId, string $date, int $quantity): array
    {
        return [
            'id' => $id,
            'date' => $date,
            'patient_id' => 1,
            'procedure_code' => $code,
            'procedure_id' => $procedureId,
            'reference_date' => '2026-09-15',
            'branch_id' => 1,
            'quantity' => $quantity,
        ];
    }

    private function createDocument(int $id, string $type, string $period, string $path, string $createdAt): void
    {
        DB::table('documents')->insert([
            'id' => $id,
            'patient_id' => 1,
            'user_id' => 1,
            'type' => $type,
            'mime_type' => 'application/json',
            'name' => $type.'_'.$period,
            'path' => $path,
            'period' => $period,
            'branch_id' => 1,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    /** @param array<string, array<string, mixed>> $payloads */
    private function storePayloads(array $payloads): void
    {
        Storage::swap(new class($payloads) {
            public function __construct(private array $payloads)
            {
            }

            public function disk(string $name): self
            {
                return $this;
            }

            public function exists(string $path): bool
            {
                return array_key_exists($path, $this->payloads);
            }

            public function get(string $path): string
            {
                return json_encode($this->payloads[$path], JSON_THROW_ON_ERROR);
            }
        });
    }
}
