<?php

namespace App\Services\DocumentationAudit;

use App\Models\DocumentationAuditIssue;
use App\Models\Patient;
use App\Services\PatientNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class DocumentationAuditNotificationService
{
    public function __construct(private PatientNotificationService $notifications)
    {
    }

    /** @param Collection<int, int> $patientIds */
    public function synchronize(string $period, Collection $patientIds): void
    {
        $patients = Patient::query()->withTrashed()->whereKey($patientIds->unique()->all())->get()->keyBy('id');

        foreach ($patientIds->unique() as $patientId) {
            $patient = $patients->get($patientId);
            if (! $patient) {
                continue;
            }

            $issues = DocumentationAuditIssue::query()
                ->where('patient_id', $patientId)
                ->where('period', $period)
                ->where('status', DocumentationAuditIssue::STATUS_OPEN)
                ->orderBy('id')
                ->get();

            $this->notifications->resolveIssue($patient, $this->legacyKey((int) $patientId, $period));
            $issuesByRule = $issues->groupBy('rule_code');
            $ruleCodes = collect(config('documentation_audit.rules', []))->keys();

            $patientName = trim(implode(' ', array_filter([
                $patient->title,
                $patient->first_name,
                $patient->last_name,
            ])));

            foreach ($ruleCodes as $ruleCode) {
                $ruleIssues = $issuesByRule->get($ruleCode, collect());
                $key = $this->key((int) $patientId, $period, (string) $ruleCode);

                if ($ruleIssues->isEmpty() || $patient->trashed()) {
                    $this->notifications->resolveIssue($patient, $key);
                    continue;
                }

                $this->reportRuleIssues($patient, $patientName, $period, (string) $ruleCode, $ruleIssues, $key);
            }
        }
    }

    /** @param Collection<int, DocumentationAuditIssue> $issues */
    private function reportRuleIssues(
        Patient $patient,
        string $patientName,
        string $period,
        string $ruleCode,
        Collection $issues,
        string $key,
    ): void {
        $count = $issues->count();
        $month = CarbonImmutable::createFromFormat('!Y-m', $period)->format('m/Y');
        $problemSummaries = $issues
            ->take(3)
            ->map(fn (DocumentationAuditIssue $issue): string => $this->problemSummary($issue))
            ->values();
        if ($count > 3) {
            $problemSummaries->push('A ďalšie '.($count - 3).' '.($count - 3 === 1 ? 'problém.' : 'problémy.'));
        }

        $this->notifications->reportIssueToAssignedNurse(
            $patient,
            $key,
            'documentation_audit_issue',
            "{$patientName} – {$this->actionTitle($ruleCode)}",
            "{$month}: ".$problemSummaries->implode(' '),
            [
                'patient_id' => (int) $patient->id,
                'branch_id' => $patient->branch_id ? (int) $patient->branch_id : null,
                'patient_name' => $patientName,
                'period' => $period,
                'issue_count' => $count,
                'issue_ids' => $issues->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                'rule_codes' => [$ruleCode],
                'problem_summaries' => $problemSummaries->all(),
                'severity' => $this->highestSeverity($issues->pluck('severity')),
                'target_route' => $this->targetRoute($ruleCode),
            ],
        );
    }

    private function key(int $patientId, string $period, string $ruleCode): string
    {
        return "documentation-audit:patient:{$patientId}:period:{$period}:rule:{$ruleCode}";
    }

    private function legacyKey(int $patientId, string $period): string
    {
        return "documentation-audit:patient:{$patientId}:period:{$period}";
    }

    private function actionTitle(string $ruleCode): string
    {
        return match ($ruleCode) {
            'DOC-001' => 'doplniť Dekurz',
            'DOC-002' => 'doplniť Dohodu',
            'DOC-003' => 'doplniť vstupný záznam',
            'DOC-004' => 'doplniť Návrh',
            'DOC-005' => 'doplniť prepúšťaciu správu',
            default => 'doplniť dokumentáciu',
        };
    }

    private function targetRoute(string $ruleCode): string
    {
        return match ($ruleCode) {
            'DOC-001' => 'dekurz',
            'DOC-002' => 'agreement',
            'DOC-003' => 'record',
            'DOC-004' => 'proposal',
            'DOC-005' => 'leave',
            default => 'dekurz',
        };
    }

    private function problemSummary(DocumentationAuditIssue $issue): string
    {
        $metadata = $issue->metadata ?? [];
        $procedureCode = (string) ($metadata['procedure_code'] ?? '');
        $date = $issue->date ? CarbonImmutable::parse($issue->date)->format('d.m.Y') : null;
        $procedure = $procedureCode !== '' ? "výkon {$procedureCode}" : 'účtovaný výkon';
        $datedProcedure = $date ? "{$procedure} z {$date}" : $procedure;

        return match ($issue->rule_code) {
            'DOC-001' => "Vo finalizovanom Dekurze chýba {$datedProcedure}.",
            'DOC-002' => "Chýba Dohoda pre {$datedProcedure}.",
            'DOC-003' => "Chýba vstupný ošetrovateľský záznam pre {$datedProcedure}.",
            'DOC-004' => "Chýba Návrh pre {$datedProcedure}.",
            'DOC-005' => "Chýba prepúšťacia správa pre {$datedProcedure}.",
            default => "Dokumentácia vyžaduje kontrolu ({$issue->rule_code}).",
        };
    }

    /** @param Collection<int, string> $severities */
    private function highestSeverity(Collection $severities): string
    {
        foreach (['error', 'warning', 'info'] as $severity) {
            if ($severities->contains($severity)) {
                return $severity;
            }
        }

        return (string) ($severities->first() ?? 'error');
    }
}