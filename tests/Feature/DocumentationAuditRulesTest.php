<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Document;
use App\Models\Patient;
use App\Models\PatientPoint;
use App\Models\Procedure;
use App\Services\DocumentationAudit\DocumentationAuditContext;
use App\Services\DocumentationAudit\Rules\CheckAgreementForAdmissionRule;
use App\Services\DocumentationAudit\Rules\CheckFinalizedDekurzCoverageRule;
use App\Services\DocumentationAudit\Rules\CheckFinalReportForKosEndRule;
use App\Services\DocumentationAudit\Rules\CheckNursingAssessmentForAdmissionRule;
use App\Services\DocumentationAudit\Rules\CheckProposalForKosPlanRule;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\TestCase;

class DocumentationAuditRulesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        Container::setInstance($app);
        $app->instance('config', new Repository([
            'documentation_audit' => [
                'documentable_procedure_codes' => ['3423B'],
                'rules' => [
                    'DOC-002' => ['procedure_codes' => ['3390'], 'document_type' => 'agreement'],
                    'DOC-003' => ['procedure_codes' => ['3390'], 'document_type' => 'record', 'match_period' => true],
                    'DOC-004' => ['procedure_codes' => ['3393'], 'document_type' => 'proposal', 'match_period' => true],
                    'DOC-005' => ['procedure_codes' => ['3395'], 'document_type' => 'leave', 'match_period' => true],
                ],
            ],
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
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    public function test_documentable_points_are_matched_to_finalized_v2_entries_by_snapshot_id(): void
    {
        $point = $this->point(10, '3423B', '2026-09-12');
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

        $covered = $this->context([$point], [$this->document(1, 'dekurz', '2026-09', 'dekurz/v2.json')]);
        $missing = $this->context([$point], []);

        self::assertCount(0, (new CheckFinalizedDekurzCoverageRule())->audit($covered));
        self::assertSame([10], (new CheckFinalizedDekurzCoverageRule())->audit($missing)->pluck('patientPointId')->all());
    }

    public function test_drafts_do_not_count_administrative_codes_are_ignored_and_equal_rows_remain_independent(): void
    {
        $documentableA = $this->point(10, '3423B', '2026-09-12');
        $documentableB = $this->point(11, '3423B', '2026-09-12');
        $administrative = $this->point(12, '3390', '2026-09-12');

        $findings = (new CheckFinalizedDekurzCoverageRule())->audit(
            $this->context([$documentableA, $documentableB, $administrative], []),
        );

        self::assertSame([10, 11], $findings->pluck('patientPointId')->all());
    }

    public function test_legacy_v1_dekurz_is_reported_as_non_verifiable_without_fabricated_missing_issue(): void
    {
        $this->storePayloads(['dekurz/v1.json' => ['schema_version' => 1, 'days' => [['text' => 'free text']]]]);
        $context = $this->context(
            [$this->point(10, '3423B', '2026-09-12')],
            [$this->document(1, 'dekurz', '2026-09', 'dekurz/v1.json')],
        );

        self::assertCount(0, (new CheckFinalizedDekurzCoverageRule())->audit($context));
        self::assertSame(1, $context->diagnostics()['legacy_not_structurally_verifiable']);
    }

    public function test_finalized_dekurz_coverage_starts_on_configured_rule_date(): void
    {
        config()->set('documentation_audit.rules.DOC-001.start_date', '2026-11-01');
        $beforeStart = $this->point(10, '3423B', '2026-10-31');
        $onStart = $this->point(11, '3423B', '2026-11-01');

        $findings = (new CheckFinalizedDekurzCoverageRule())->audit(
            $this->context([$beforeStart, $onStart], []),
        );

        self::assertSame([11], $findings->pluck('patientPointId')->all());
    }

    public function test_3390_requires_agreement_and_same_period_nursing_record(): void
    {
        $point = $this->point(20, '3390', '2026-09-03');
        $missing = $this->context([$point], []);
        $documents = $this->context([$point], [
            $this->document(2, 'agreement', '2026-08', 'agreements/one.json'),
            $this->document(3, 'record', '2026-09', 'records/one.json'),
        ]);
        $this->storePayloads([
            'agreements/one.json' => ['date' => '2026-08-28'],
            'records/one.json' => [],
        ]);

        self::assertCount(1, (new CheckAgreementForAdmissionRule())->audit($missing));
        self::assertCount(1, (new CheckNursingAssessmentForAdmissionRule())->audit($missing));
        self::assertCount(0, (new CheckAgreementForAdmissionRule())->audit($documents));
        self::assertCount(0, (new CheckNursingAssessmentForAdmissionRule())->audit($documents));
    }

    public function test_3393_requires_proposal_and_3395_requires_same_period_final_report(): void
    {
        $proposalPoint = $this->point(30, '3393', '2026-09-01');
        $endPoint = $this->point(31, '3395', '2026-09-30');
        $missing = $this->context([$proposalPoint, $endPoint], []);
        $documents = $this->context([$proposalPoint, $endPoint], [
            $this->document(4, 'proposal', '2026-09', 'proposals/one.json'),
            $this->document(5, 'leave', '2026-09', 'leave/one.json'),
        ]);
        $this->storePayloads([
            'proposals/one.json' => ['date' => '2026-09-01'],
            'leave/one.json' => ['date' => '2026-09-30'],
        ]);

        self::assertCount(1, (new CheckProposalForKosPlanRule())->audit($missing));
        self::assertCount(1, (new CheckFinalReportForKosEndRule())->audit($missing));
        self::assertCount(0, (new CheckProposalForKosPlanRule())->audit($documents));
        self::assertCount(0, (new CheckFinalReportForKosEndRule())->audit($documents));
    }

    /** @param array<int, PatientPoint> $points @param array<int, Document> $documents */
    private function context(array $points, array $documents): DocumentationAuditContext
    {
        $branch = new Branch(['company_id' => 7]);
        $branch->id = 3;
        $patient = new Patient(['branch_id' => 3]);
        $patient->id = 1;
        $patient->setRelation('branch', $branch);

        foreach ($points as $point) {
            $point->setRelation('branch', $branch);
        }

        return new DocumentationAuditContext($patient, '2026-09', collect($points), collect($documents));
    }

    private function point(int $id, string $code, string $date): PatientPoint
    {
        $point = new PatientPoint([
            'patient_id' => 1,
            'branch_id' => 3,
            'user_id' => 8,
            'procedure_id' => $id,
            'procedure_code' => $code,
            'date' => $date,
            'quantity' => 1,
        ]);
        $point->id = $id;
        $procedure = new Procedure(['code' => $code, 'description' => "Procedure {$code}"]);
        $procedure->id = $id;
        $point->setRelation('procedure', $procedure);

        return $point;
    }

    private function document(int $id, string $type, string $period, string $path): Document
    {
        $document = new Document(['type' => $type, 'period' => $period, 'path' => $path]);
        $document->id = $id;

        return $document;
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