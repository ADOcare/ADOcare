<?php

namespace Tests\Feature;

use App\Models\DocumentationAuditIssue;
use App\Models\AppNotification;
use App\Services\DocumentationAudit\DocumentationAuditIssueRepository;
use App\Services\DocumentationAudit\DocumentationAuditNotificationService;
use App\Services\DocumentationAudit\DocumentationAuditPeriodSelector;
use App\Services\DocumentationAudit\DocumentationAuditService;
use App\Services\DocumentationAudit\ImmediateDocumentationAuditService;
use App\Services\DocumentationAudit\Rules\CheckFinalizedDekurzCoverageRule;
use App\Services\DocumentationAudit\Rules\CheckMedicalFindingReferenceDateRule;
use App\Services\PatientNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\TestCase;

class DocumentationAuditServiceTest extends TestCase
{
    private Container $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new Container();
        Container::setInstance($this->app);
        $this->app->instance('config', new Repository([
            'documentation_audit' => [
                'enabled' => true,
                'start_date' => '2026-08-01',
                'grace_days' => 5,
                'documentable_procedure_codes' => ['3423B'],
                'rules' => [
                    'DOC-001' => [
                        'enabled' => true,
                        'severity' => 'warning',
                        'class' => CheckFinalizedDekurzCoverageRule::class,
                    ],
                    'DOC-005' => [
                        'enabled' => false,
                        'severity' => 'error',
                    ],
                    'DOC-006' => [
                        'enabled' => false,
                        'severity' => 'error',
                        'class' => CheckMedicalFindingReferenceDateRule::class,
                    ],
                ],
            ],
        ]));

        $database = new Manager($this->app);
        $database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $database->setAsGlobal();
        $database->bootEloquent();
        $this->app->instance('db', $database->getDatabaseManager());
        $this->app->bind('db.schema', fn () => $database->schema());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);

        $this->createTables();
        $this->seedScoring();
        $this->storePayloads([]);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    public function test_audit_can_be_disabled_and_individual_rule_can_be_disabled(): void
    {
        config()->set('documentation_audit.enabled', false);
        $disabled = $this->service()->run(month: '2026-09', today: CarbonImmutable::parse('2026-10-15'));
        self::assertTrue($disabled['disabled']);
        self::assertSame(0, DocumentationAuditIssue::query()->count());

        config()->set('documentation_audit.enabled', true);
        config()->set('documentation_audit.rules.DOC-001.enabled', false);
        $withoutRule = $this->service()->run(month: '2026-09', today: CarbonImmutable::parse('2026-10-15'));
        self::assertSame([], $withoutRule['detected']);
        self::assertSame(0, DocumentationAuditIssue::query()->count());
    }

    public function test_issue_is_deduplicated_resolved_and_reopened_with_original_first_detection(): void
    {
        $first = $this->service()->run(month: '2026-09', today: CarbonImmutable::parse('2026-10-15'));
        $issue = DocumentationAuditIssue::query()->firstOrFail();
        $firstDetectedAt = $issue->first_detected_at->toISOString();

        self::assertSame(1, $first['opened']);
        self::assertSame('warning', $issue->severity);
        $notification = AppNotification::query()->firstOrFail();
        self::assertSame(8, $notification->user_id);
        self::assertSame('issue', $notification->kind);
        self::assertSame('documentation_audit_issue', $notification->type);
        self::assertTrue((bool) $notification->data['persistent']);
        self::assertSame('Anna Audit – doplniť Dekurz', $notification->title);
        self::assertStringContainsString('výkon 3423B z 12.09.2026', $notification->description);
        self::assertSame('Anna Audit', $notification->data['patient_name']);
        self::assertSame(3, $notification->data['branch_id']);
        self::assertSame('dekurz', $notification->data['target_route']);

        $second = $this->service()->run(month: '2026-09', today: CarbonImmutable::parse('2026-10-15'));
        self::assertSame(1, $second['still_open']);
        self::assertSame(1, DocumentationAuditIssue::query()->count());
        self::assertSame(1, AppNotification::query()->count());

        $this->createCoveredDekurz();
        $resolved = $this->service()->run(month: '2026-09', today: CarbonImmutable::parse('2026-10-15'));
        self::assertSame(1, $resolved['resolved']);
        self::assertSame('resolved', $issue->fresh()->status);
        self::assertNotNull($notification->fresh()->resolved_at);

        DB::table('documents')->delete();
        $reopened = $this->service()->run(month: '2026-09', today: CarbonImmutable::parse('2026-10-15'));
        self::assertSame(1, $reopened['reopened']);
        self::assertSame('open', $issue->fresh()->status);
        self::assertSame($firstDetectedAt, $issue->fresh()->first_detected_at->toISOString());
        self::assertSame(1, DocumentationAuditIssue::query()->count());
        self::assertNull($notification->fresh()->resolved_at);
        self::assertSame(1, AppNotification::query()->count());
    }

    public function test_dry_run_does_not_mutate_and_explicit_month_respects_eligibility(): void
    {
        $dryRun = $this->service()->run(
            month: '2026-09',
            dryRun: true,
            today: CarbonImmutable::parse('2026-10-15'),
        );
        self::assertSame(1, $dryRun['opened']);
        self::assertSame(0, DocumentationAuditIssue::query()->count());
        self::assertSame(0, AppNotification::query()->count());

        $duringGrace = $this->service()->run(month: '2026-09', today: CarbonImmutable::parse('2026-10-05'));
        self::assertSame(0, $duringGrace['periods_checked']);
        self::assertSame(0, DocumentationAuditIssue::query()->count());
    }

    public function test_multiple_open_issues_are_aggregated_into_one_patient_period_action(): void
    {
        DB::table('patient_points')->insert([
            'id' => 11,
            'patient_id' => 1,
            'branch_id' => 3,
            'user_id' => 8,
            'procedure_id' => 20,
            'procedure_code' => '3423B',
            'date' => '2026-09-13',
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->service()->run(month: '2026-09', today: CarbonImmutable::parse('2026-10-15'));

        self::assertSame(2, DocumentationAuditIssue::query()->count());
        self::assertSame(1, AppNotification::query()->count());
        $data = AppNotification::query()->firstOrFail()->data;
        self::assertSame(2, $data['issue_count']);
        self::assertSame(['DOC-001'], $data['rule_codes']);
    }

    public function test_different_rules_create_separate_actions_with_exact_repair_routes(): void
    {
        $this->service()->run(month: '2026-09', today: CarbonImmutable::parse('2026-10-15'));
        DocumentationAuditIssue::query()->create([
            'company_id' => 7,
            'branch_id' => 3,
            'patient_id' => 1,
            'user_id' => 8,
            'period' => '2026-09',
            'rule_code' => 'DOC-005',
            'severity' => 'error',
            'date' => '2026-09-30',
            'procedure_id' => 20,
            'patient_point_id' => 10,
            'fingerprint' => hash('sha256', 'DOC-005|1|2026-09|10'),
            'status' => DocumentationAuditIssue::STATUS_OPEN,
            'first_detected_at' => now(),
            'last_detected_at' => now(),
            'metadata' => ['procedure_code' => '3395'],
        ]);

        (new DocumentationAuditNotificationService(new PatientNotificationService()))
            ->synchronize('2026-09', collect([1]));

        self::assertSame(2, AppNotification::query()->whereNull('resolved_at')->count());
        $routes = AppNotification::query()
            ->whereNull('resolved_at')
            ->get()
            ->mapWithKeys(fn (AppNotification $notification): array => [
                $notification->data['rule_codes'][0] => $notification->data['target_route'],
            ])
            ->all();
        self::assertSame(['DOC-001' => 'dekurz', 'DOC-005' => 'leave'], $routes);
    }

    public function test_normal_scope_rechecks_old_open_period_without_reauditing_all_history(): void
    {
        DB::table('patient_points')->where('id', 10)->update(['date' => '2026-08-12']);
        $this->service()->run(month: '2026-08', today: CarbonImmutable::parse('2026-10-15'));
        DB::table('patient_points')->where('id', 10)->update(['date' => '2026-09-12']);

        $stats = $this->service()->run(today: CarbonImmutable::parse('2026-10-15'));

        self::assertSame(2, $stats['periods_checked']);
        self::assertSame(1, $stats['resolved']);
        self::assertSame('resolved', DocumentationAuditIssue::query()->where('period', '2026-08')->value('status'));
    }

    public function test_exact_start_date_excludes_earlier_points_in_first_month(): void
    {
        config()->set('documentation_audit.start_date', '2026-09-15');

        $stats = $this->service()->run(month: '2026-09', today: CarbonImmutable::parse('2026-10-15'));

        self::assertSame(0, $stats['points_checked']);
        self::assertSame(0, DocumentationAuditIssue::query()->count());
    }

    public function test_doc_006_is_grouped_resolved_by_matching_finding_and_reopened_after_soft_delete(): void
    {
        config()->set('documentation_audit.rules.DOC-001.enabled', false);
        config()->set('documentation_audit.rules.DOC-006.enabled', true);
        DB::table('patient_points')->where('id', 10)->update(['reference_date' => '2026-08-28']);
        DB::table('patient_points')->insert([
            'id' => 11,
            'patient_id' => 1,
            'branch_id' => 3,
            'user_id' => 8,
            'procedure_id' => 20,
            'procedure_code' => '3423B',
            'date' => '2026-09-13',
            'reference_date' => '2026-08-28',
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $opened = $this->service()->run(month: '2026-09', today: CarbonImmutable::parse('2026-10-15'));
        $issue = DocumentationAuditIssue::query()->firstOrFail();

        self::assertSame(1, $opened['opened']);
        self::assertSame(1, $opened['detected']['DOC-006']);
        self::assertSame('DOC-006', $issue->rule_code);
        self::assertSame('2026-08-28', CarbonImmutable::parse($issue->getRawOriginal('date'))->toDateString());
        self::assertNull($issue->patient_point_id);
        self::assertNull($issue->procedure_id);
        self::assertSame(2, $issue->metadata['affected_points_count']);
        self::assertSame([10, 11], $issue->metadata['patient_point_ids']);
        self::assertSame(hash('sha256', 'DOC-006|1|2026-09|reference_date:2026-08-28'), $issue->fingerprint);

        $notification = AppNotification::query()->firstOrFail();
        self::assertSame('Anna Audit – doplniť lekársky nález', $notification->title);
        self::assertStringContainsString('Chýba lekársky nález z 28.08.2026.', $notification->description);
        self::assertSame('points', $notification->data['target_route']);

        DB::table('documents')->insert([
            'id' => 60,
            'patient_id' => 1,
            'user_id' => 8,
            'type' => 'scan',
            'path' => 'scans/finding.json',
            'period' => '2026-08',
            'reference_date' => '2026-08-28',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resolved = $this->service()->run(month: '2026-09', today: CarbonImmutable::parse('2026-10-15'));
        self::assertSame(1, $resolved['resolved']);
        self::assertSame(DocumentationAuditIssue::STATUS_RESOLVED, $issue->fresh()->status);

        DB::table('documents')->where('id', 60)->update(['deleted_at' => now()]);
        $reopened = $this->service()->run(month: '2026-09', today: CarbonImmutable::parse('2026-10-15'));
        self::assertSame(1, $reopened['reopened']);
        self::assertSame(DocumentationAuditIssue::STATUS_OPEN, $issue->fresh()->status);
        self::assertSame(1, DocumentationAuditIssue::query()->count());
    }

    public function test_immediate_run_checks_open_period_without_running_doc_001(): void
    {
        config()->set('documentation_audit.rules.DOC-006.enabled', true);
        DB::table('patient_points')->where('id', 10)->update([
            'date' => '2026-10-12',
            'reference_date' => '2026-09-28',
        ]);

        $stats = $this->service()->runImmediate('2026-10', 1, ['DOC-001', 'DOC-006']);

        self::assertSame(1, $stats['periods_checked']);
        self::assertSame(['DOC-006' => 1], $stats['detected']);
        self::assertSame(['DOC-006'], DocumentationAuditIssue::query()->pluck('rule_code')->all());
    }

    public function test_immediate_coordinator_opens_resolves_and_reopens_current_period_issue(): void
    {
        config()->set('documentation_audit.rules.DOC-001.enabled', false);
        config()->set('documentation_audit.rules.DOC-006.enabled', true);
        DB::table('patient_points')->where('id', 10)->update([
            'date' => '2026-10-12',
            'reference_date' => '2026-09-28',
        ]);
        $point = \App\Models\PatientPoint::query()->findOrFail(10);
        $coordinator = new ImmediateDocumentationAuditService($this->service());

        $coordinator->patientPointChanged($point);
        $issue = DocumentationAuditIssue::query()->firstOrFail();
        self::assertSame(DocumentationAuditIssue::STATUS_OPEN, $issue->status);

        DB::table('documents')->insert([
            'id' => 60,
            'patient_id' => 1,
            'user_id' => 8,
            'type' => 'scan',
            'path' => 'scans/finding.json',
            'period' => '2026-09',
            'reference_date' => '2026-09-28',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $document = \App\Models\Document::query()->findOrFail(60);

        $coordinator->documentsChanged([$document]);
        self::assertSame(DocumentationAuditIssue::STATUS_RESOLVED, $issue->fresh()->status);

        $document->delete();
        $coordinator->documentsChanged([$document]);
        self::assertSame(DocumentationAuditIssue::STATUS_OPEN, $issue->fresh()->status);
        self::assertSame(1, DocumentationAuditIssue::query()->count());
    }

    private function service(): DocumentationAuditService
    {
        return new DocumentationAuditService(
            new DocumentationAuditPeriodSelector(),
            new DocumentationAuditIssueRepository(),
            new DocumentationAuditNotificationService(new PatientNotificationService()),
        );
    }

    private function createCoveredDekurz(): void
    {
        DB::table('documents')->insert([
            'id' => 50,
            'patient_id' => 1,
            'user_id' => 8,
            'type' => 'dekurz',
            'path' => 'dekurz/v2.json',
            'period' => '2026-09',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->storePayloads([
            'dekurz/v2.json' => [
                'schema_version' => 2,
                'source' => 'dekurz_v2',
                'days' => [[
                    'date' => '2026-09-12',
                    'entries' => [['patient_point_snapshot_id' => 10]],
                ]],
            ],
        ]);
    }

    private function seedScoring(): void
    {
        DB::table('branches')->insert(['id' => 3, 'company_id' => 7]);
        DB::table('users')->insert(['id' => 8]);
        DB::table('patients')->insert([
            'id' => 1,
            'first_name' => 'Anna',
            'last_name' => 'Audit',
            'branch_id' => 3,
            'nurse_id' => 8,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('procedures')->insert(['id' => 20, 'code' => '3423B', 'description' => 'Preväz rany']);
        DB::table('patient_points')->insert([
            'id' => 10,
            'patient_id' => 1,
            'branch_id' => 3,
            'user_id' => 8,
            'procedure_id' => 20,
            'procedure_code' => '3423B',
            'date' => '2026-09-12',
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createTables(): void
    {
        Schema::create('branches', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('company_id')->nullable();
        });
        Schema::create('patients', function ($table) {
            $table->increments('id');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->unsignedInteger('branch_id')->nullable();
            $table->unsignedBigInteger('nurse_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('procedures', function ($table) {
            $table->increments('id');
            $table->string('code');
            $table->string('description')->nullable();
        });
        Schema::create('users', function ($table) {
            $table->id();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
        Schema::create('patient_points', function ($table) {
            $table->increments('id');
            $table->date('date');
            $table->date('reference_date')->nullable();
            $table->unsignedInteger('patient_id');
            $table->unsignedInteger('procedure_id')->nullable();
            $table->string('procedure_code');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('branch_id')->nullable();
            $table->smallInteger('quantity')->nullable();
            $table->timestamps();
        });
        Schema::create('documents', function ($table) {
            $table->id();
            $table->unsignedInteger('patient_id')->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->string('type');
            $table->string('path')->nullable();
            $table->string('period', 7)->nullable();
            $table->date('reference_date')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('documentation_audit_issues', function ($table) {
            $table->id();
            $table->unsignedInteger('company_id');
            $table->unsignedInteger('branch_id')->nullable();
            $table->unsignedInteger('patient_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('period', 7);
            $table->string('rule_code');
            $table->string('severity');
            $table->date('date')->nullable();
            $table->unsignedInteger('procedure_id')->nullable();
            $table->unsignedInteger('patient_point_id')->nullable();
            $table->unsignedBigInteger('document_id')->nullable();
            $table->char('fingerprint', 64)->unique();
            $table->string('status');
            $table->timestamp('first_detected_at');
            $table->timestamp('last_detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->json('metadata')->nullable();
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