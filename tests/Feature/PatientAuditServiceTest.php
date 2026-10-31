<?php

namespace Tests\Feature;

use App\Http\Filters\ApiQuery;
use App\Models\Patient;
use App\Models\PatientAuditIssue;
use App\Services\PatientAuditNotificationService;
use App\Services\PatientAuditService;
use App\Services\PatientNotificationService;
use App\Services\PatientPersonalNumberValidator;
use Carbon\Carbon;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class PatientAuditServiceTest extends TestCase
{
    private Container $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new Container;
        Container::setInstance($this->app);
        $this->app->instance('config', new Repository);

        $database = new Manager($this->app);
        $database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $database->setAsGlobal();
        $database->bootEloquent();
        $this->app->instance('db', $database->getDatabaseManager());
        $this->app->bind('db.schema', fn () => $database->schema());

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        $this->createTables();
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

    public function test_personal_number_validator_normalizes_and_validates_dates_and_checksums(): void
    {
        $validator = new PatientPersonalNumberValidator;

        self::assertSame('8001010006', $validator->normalize('800101/0006'));
        self::assertTrue($validator->validate('8001010006')['valid']);
        self::assertTrue($validator->validate('800101 0006')['valid']);
        self::assertTrue($validator->validate('8001010007')['valid']);
        self::assertTrue($validator->validate('8551010006')['valid']);
        self::assertTrue($validator->validate('530101123')['valid']);
        self::assertSame('invalid_length', $validator->validate('80010112345')['reason']);
        self::assertSame('invalid_date', $validator->validate('8002310000')['reason']);
        self::assertSame('invalid_checksum', $validator->validate('8501010007')['reason']);
    }

    public function test_patient_list_includes_deceased_and_excludes_soft_deleted_even_with_legacy_flags(): void
    {
        $this->insertPatient(1, ['personal_number' => '8001010006']);
        $this->insertPatient(2, ['personal_number' => '8551010006', 'death_date' => '2025-01-01']);
        $this->insertPatient(3, ['personal_number' => '9001010006', 'deleted_at' => '2026-10-01 10:00:00']);

        $request = Request::create('/?only_deleted=1&with_deleted=1');
        $query = Patient::query();
        $filters = new ReflectionMethod(ApiQuery::class, 'applyFilters');
        $filters->setAccessible(true);
        $filters->invoke(null, $request, $query, 'all', [], false);
        $results = $query->orderBy('id')->get();

        self::assertSame([1, 2], $results->modelKeys());
        self::assertSame('2025-01-01', $results->last()->death_date);
    }

    public function test_audit_detects_all_rules_reconciles_and_reopens_resolved_issues(): void
    {
        DB::table('branches')->insert([
            ['id' => 1, 'company_id' => 7],
            ['id' => 2, 'company_id' => 7],
            ['id' => 3, 'company_id' => 8],
        ]);
        $this->insertPatient(1, [
            'branch_id' => 1,
            'personal_number' => '8501010007',
            'address' => null,
            'city' => null,
            'zip' => null,
            'latitude' => 91,
            'longitude' => null,
            'death_date' => '2026-10-31',
        ]);
        $this->insertPatient(2, ['branch_id' => 1, 'personal_number' => '8001010006']);
        $this->insertPatient(3, ['branch_id' => 2, 'personal_number' => '800101/0006']);
        $this->insertPatient(4, ['branch_id' => 3, 'personal_number' => null]);
        $this->insertPatient(5, [
            'branch_id' => 2,
            'personal_number' => '8001010006',
            'deleted_at' => '2026-10-01 10:00:00',
        ]);
        $this->insertPatient(6, ['branch_id' => 3, 'personal_number' => '8001010006']);

        DB::table('patients')->whereIn('id', [1, 2, 3, 4])->update(['nurse_id' => 1]);
        $audit = new PatientAuditService(
            new PatientPersonalNumberValidator,
            new PatientAuditNotificationService(new PatientNotificationService),
        );
        $first = $audit->run();

        self::assertSame(5, $first['patients_checked']);
        self::assertSame(
            10,
            $first['issues_found'],
        );
        self::assertSame(10, $first['opened']);
        self::assertSame(0, $first['still_open']);
        self::assertSame(
            ['PAT-001', 'PAT-002', 'PAT-003', 'PAT-004', 'PAT-005', 'PAT-006', 'PAT-007', 'PAT-008', 'PAT-009'],
            PatientAuditIssue::query()->distinct()->orderBy('code')->pluck('code')->all(),
        );
        $duplicateIssue = PatientAuditIssue::query()
            ->where('patient_id', 2)
            ->where('code', 'PAT-003')
            ->firstOrFail();
        self::assertSame([2, 3], $duplicateIssue->metadata['duplicate_patient_ids']);
        self::assertSame(
            0,
            PatientAuditIssue::query()->where('patient_id', 6)->where('code', 'PAT-003')->count(),
        );
        self::assertSame(4, DB::table('app_notifications')->count());
        $patientNotification = DB::table('app_notifications')
            ->where('key', 'patient-audit:patient:1')
            ->first();
        self::assertSame('patient_audit_issue', $patientNotification->type);
        self::assertSame('issue', $patientNotification->kind);
        self::assertSame(1, $patientNotification->user_id);
        self::assertTrue((bool) json_decode($patientNotification->data, true)['persistent']);

        $second = $audit->run();
        self::assertSame(0, $second['opened']);
        self::assertSame(10, $second['still_open']);
        self::assertSame(10, PatientAuditIssue::query()->count());

        DB::table('patients')->whereIn('id', [1, 2, 3])->update([
            'address' => 'Hlavná 1',
            'city' => 'Bratislava',
            'zip' => '81101',
            'latitude' => 48.1486,
            'longitude' => 17.1077,
            'death_date' => null,
        ]);
        DB::table('patients')->where('id', 1)->update(['personal_number' => '8001010006']);
        DB::table('patients')->where('id', 2)->update(['personal_number' => '8551010006']);
        DB::table('patients')->where('id', 3)->update(['personal_number' => '9001010007']);
        DB::table('patients')->where('id', 4)->update(['personal_number' => '530101123']);

        $resolved = $audit->run();
        self::assertSame(10, $resolved['resolved']);
        self::assertSame(0, PatientAuditIssue::query()->where('status', PatientAuditIssue::STATUS_OPEN)->count());
        self::assertSame(
            0,
            DB::table('app_notifications')->whereNull('resolved_at')->count(),
        );

        Carbon::setTestNow('2026-10-31 12:00:00');
        DB::table('patients')->where('id', 4)->update(['personal_number' => '']);
        $reopened = $audit->run();
        $issue = PatientAuditIssue::query()->where('patient_id', 4)->where('code', 'PAT-001')->firstOrFail();

        self::assertSame(1, $reopened['reopened']);
        self::assertSame(PatientAuditIssue::STATUS_OPEN, $issue->status);
        self::assertNull($issue->resolved_at);
        self::assertSame('2026-10-31 12:00:00', $issue->detected_at->format('Y-m-d H:i:s'));
        self::assertSame(
            1,
            DB::table('app_notifications')->where('key', 'patient-audit:patient:4')->whereNull('resolved_at')->count(),
        );
    }

    public function test_patient_issue_badge_count_only_includes_unresolved_findings(): void
    {
        $this->insertPatient(1);
        $this->insertPatient(2);
        foreach (['open', 'open', 'resolved'] as $index => $status) {
            PatientAuditIssue::query()->create([
                'patient_id' => 1,
                'code' => 'PAT-00'.($index + 1),
                'severity' => 'warning',
                'fingerprint' => 'finding-'.$index,
                'message' => 'Test finding',
                'status' => $status,
                'detected_at' => now(),
            ]);
        }

        $patients = Patient::query()->withCount('openPatientAuditIssues')->orderBy('id')->get();
        self::assertSame(2, $patients[0]->open_patient_audit_issues_count);
        self::assertSame(0, $patients[1]->open_patient_audit_issues_count);

        $patients[0]->openPatientAuditIssues()->update(['status' => PatientAuditIssue::STATUS_RESOLVED]);
        self::assertSame(0, $patients[0]->fresh()->loadCount('openPatientAuditIssues')->open_patient_audit_issues_count);
    }

    private function insertPatient(int $id, array $attributes = []): void
    {
        DB::table('patients')->insert(array_merge([
            'id' => $id,
            'first_name' => "Patient {$id}",
            'last_name' => 'Test',
            'personal_number' => '8001010006',
            'address' => 'Hlavná 1',
            'city' => 'Bratislava',
            'zip' => '81101',
            'latitude' => 48.1486,
            'longitude' => 17.1077,
            'branch_id' => null,
            'death_date' => null,
            'deleted_at' => null,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ], $attributes));
    }

    private function createTables(): void
    {
        Schema::create('branches', function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('company_id');
        });
        Schema::create('users', function ($table): void {
            $table->increments('id');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
        Schema::create('patients', function ($table): void {
            $table->increments('id');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('personal_number')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('zip')->nullable();
            $table->double('latitude')->nullable();
            $table->double('longitude')->nullable();
            $table->date('death_date')->nullable();
            $table->unsignedInteger('branch_id')->nullable();
            $table->unsignedInteger('nurse_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('app_notifications', function ($table): void {
            $table->id();
            $table->unsignedInteger('user_id');
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
        DB::table('users')->insert([
            'id' => 1,
            'first_name' => 'Nina',
            'last_name' => 'Sestra',
        ]);
        $migration = require __DIR__.'/../../database/migrations/2026_10_30_000000_create_patient_audit_issues_table.php';
        $migration->up();
    }
}
