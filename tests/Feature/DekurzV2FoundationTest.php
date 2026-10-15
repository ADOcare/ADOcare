<?php

namespace Tests\Feature;

use App\Models\DekurzDay;
use App\Models\DekurzDraft;
use App\Models\DekurzEntry;
use App\Models\Document;
use App\Models\Patient;
use App\Models\PatientPoint;
use App\Models\PatientProcedureDekurzTemplate;
use App\Services\DekurzDocumentService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\TestCase;

class DekurzV2FoundationTest extends TestCase
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
        DB::statement('PRAGMA foreign_keys = ON');

        $this->createExistingTables();
        (require __DIR__.'/../../database/migrations/2026_10_15_000000_create_dekurz_v2_foundation_tables.php')->up();
        $this->seedExistingRecords();
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        parent::tearDown();
    }

    public function test_patient_procedure_template_is_saved_once_per_combination(): void
    {
        $template = PatientProcedureDekurzTemplate::create([
            'patient_id' => 1,
            'procedure_id' => 10,
            'text' => 'Aplikovaný liek podľa ordinácie lekára.',
            'created_by' => 1,
        ]);

        self::assertSame(1, $template->patient->id);
        self::assertSame('3416', $template->procedure->code);
        self::assertSame(1, $template->creator->id);

        $this->expectException(QueryException::class);
        PatientProcedureDekurzTemplate::create([
            'patient_id' => 1,
            'procedure_id' => 10,
            'text' => 'Duplicitná šablóna.',
        ]);
    }

    public function test_monthly_draft_supports_multiple_days_and_entries_without_losing_scoring_rows(): void
    {
        $draft = $this->createDraft();
        $firstDay = $draft->days()->create(['date' => '2026-10-12']);
        $draft->days()->create(['date' => '2026-10-13', 'general_note' => 'Kontrola stavu.']);

        $firstDay->entries()->create([
            'procedure_id' => 10,
            'patient_point_id' => 100,
            'quantity' => 2,
            'text' => 'Prvý výkon.',
            'source' => 'generated',
        ]);
        $firstDay->entries()->create([
            'procedure_id' => 10,
            'patient_point_id' => 101,
            'quantity' => 1,
            'text' => 'Druhý účtovaný riadok rovnakého výkonu.',
            'source' => 'patient_template',
        ]);
        $firstDay->entries()->create([
            'procedure_id' => 11,
            'text' => 'Ďalší výkon v rovnaký deň.',
            'source' => 'manual',
        ]);

        self::assertCount(2, $draft->fresh()->days);
        self::assertCount(3, $firstDay->fresh()->entries);
        self::assertSame([10, 10, 11], $firstDay->entries()->orderBy('id')->pluck('procedure_id')->all());
        self::assertSame(2, $firstDay->entries()->where('patient_point_id', 100)->firstOrFail()->quantity);
        self::assertSame('3423B', $firstDay->entries()->where('procedure_id', 11)->firstOrFail()->procedure->code);
        self::assertSame(1, PatientPoint::findOrFail(100)->dekurzEntry->day->draft->patient->id);
    }

    public function test_same_date_cannot_be_added_twice_to_one_draft(): void
    {
        $draft = $this->createDraft();
        $draft->days()->create(['date' => '2026-10-12']);

        $this->expectException(QueryException::class);
        $draft->days()->create(['date' => '2026-10-12']);
    }

    public function test_patient_cannot_have_two_drafts_for_the_same_month(): void
    {
        $this->createDraft();

        $this->expectException(QueryException::class);
        $this->createDraft(['period' => '2026-10-15']);
    }

    public function test_deletion_and_editing_keep_templates_drafts_and_historical_documents_separate(): void
    {
        $template = PatientProcedureDekurzTemplate::create([
            'patient_id' => 1,
            'procedure_id' => 10,
            'text' => 'Opakovateľný text.',
        ]);
        $draft = $this->createDraft(['final_document_id' => 50]);
        $day = $draft->days()->create(['date' => '2026-10-12']);
        $entry = $day->entries()->create([
            'procedure_id' => 10,
            'patient_point_id' => 100,
            'quantity' => 2,
            'text' => 'Denný text.',
        ]);

        $entry->update(['text' => 'Individuálne upravený denný text.', 'edited_at' => now()]);
        self::assertSame('Opakovateľný text.', $template->fresh()->text);

        PatientPoint::findOrFail(100)->delete();
        self::assertNull($entry->fresh()->patient_point_id);
        self::assertSame(2, $entry->fresh()->quantity);

        DB::table('documents')->where('id', 50)->delete();
        self::assertNull($draft->fresh()->final_document_id);

        $day->delete();
        self::assertFalse(DekurzEntry::whereKey($entry->id)->exists());
    }

    public function test_legacy_snapshot_without_schema_version_is_loaded_unchanged(): void
    {
        $payload = [
            'document_id' => 50,
            'patient_id' => 1,
            'sections' => [['text' => 'Historický text.', 'dates' => ['2026-09-01']]],
            'days' => [['date' => '2026-09-01', 'text' => 'Historický text.']],
        ];
        Storage::swap(new class($payload) {
            public function __construct(private array $payload)
            {
            }

            public function disk(string $name): self
            {
                return $this;
            }

            public function exists(string $path): bool
            {
                return $path === 'dekurz/legacy.json';
            }

            public function get(string $path): string
            {
                return json_encode($this->payload, JSON_THROW_ON_ERROR);
            }
        });

        $document = (new Document())->forceFill([
            'id' => 50,
            'patient_id' => 1,
            'path' => 'dekurz/legacy.json',
        ]);

        self::assertSame($payload, (new DekurzDocumentService())->findDekurzFileForDocument($document));
        self::assertArrayNotHasKey('schema_version', $payload);
    }

    private function createDraft(array $attributes = []): DekurzDraft
    {
        return DekurzDraft::create(array_merge([
            'patient_id' => 1,
            'branch_id' => 1,
            'period' => '2026-10-01',
            'created_by' => 1,
        ], $attributes));
    }

    private function createExistingTables(): void
    {
        Schema::create('users', function ($table) {
            $table->id();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('branches', function ($table) {
            $table->increments('id');
            $table->timestamps();
        });
        Schema::create('patients', function ($table) {
            $table->increments('id');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
        Schema::create('procedures', function ($table) {
            $table->increments('id');
            $table->string('code')->nullable();
            $table->timestamps();
        });
        Schema::create('patient_points', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('patient_id')->nullable();
            $table->unsignedInteger('procedure_id')->nullable();
            $table->smallInteger('quantity')->nullable();
            $table->date('date')->nullable();
            $table->timestamps();
        });
        Schema::create('documents', function ($table) {
            $table->id();
            $table->timestamps();
        });
    }

    private function seedExistingRecords(): void
    {
        DB::table('users')->insert(['id' => 1]);
        DB::table('branches')->insert(['id' => 1]);
        DB::table('patients')->insert(['id' => 1, 'first_name' => 'Anna', 'last_name' => 'Pacientová']);
        DB::table('procedures')->insert([
            ['id' => 10, 'code' => '3416'],
            ['id' => 11, 'code' => '3423B'],
        ]);
        DB::table('patient_points')->insert([
            ['id' => 100, 'patient_id' => 1, 'procedure_id' => 10, 'quantity' => 2, 'date' => '2026-10-12'],
            ['id' => 101, 'patient_id' => 1, 'procedure_id' => 10, 'quantity' => 1, 'date' => '2026-10-12'],
        ]);
        DB::table('documents')->insert(['id' => 50]);
    }
}