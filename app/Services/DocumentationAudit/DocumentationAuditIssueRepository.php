<?php

namespace App\Services\DocumentationAudit;

use App\Models\DocumentationAuditIssue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DocumentationAuditIssueRepository
{
    /**
     * @param Collection<int, DocumentationAuditFinding> $findings
     * @param array<int, string> $ruleCodes
     * @return array{opened: int, reopened: int, still_open: int, resolved: int}
     */
    public function synchronize(
        string $period,
        Collection $findings,
        array $ruleCodes,
        bool $dryRun = false,
        ?int $patientId = null,
        ?int $companyId = null,
    ): array {
        $findings = $findings->unique(fn (DocumentationAuditFinding $finding): string => $finding->fingerprint())->values();

        $synchronize = function () use ($period, $findings, $ruleCodes, $dryRun, $patientId, $companyId): array {
            $existing = $this->scope($period, $ruleCodes, $patientId, $companyId)
                ->lockForUpdate()
                ->get()
                ->keyBy('fingerprint');
            $detected = [];
            $stats = ['opened' => 0, 'reopened' => 0, 'still_open' => 0, 'resolved' => 0];
            $detectedAt = now();

            foreach ($findings as $finding) {
                $fingerprint = $finding->fingerprint();
                $detected[] = $fingerprint;
                /** @var DocumentationAuditIssue|null $issue */
                $issue = $existing->get($fingerprint);

                if (! $issue) {
                    $stats['opened']++;
                    if (! $dryRun) {
                        DocumentationAuditIssue::query()->create($this->attributes($finding) + [
                            'fingerprint' => $fingerprint,
                            'status' => DocumentationAuditIssue::STATUS_OPEN,
                            'first_detected_at' => $detectedAt,
                            'last_detected_at' => $detectedAt,
                        ]);
                    }
                    continue;
                }

                if ($issue->status === DocumentationAuditIssue::STATUS_RESOLVED) {
                    $stats['reopened']++;
                } else {
                    $stats['still_open']++;
                }

                if (! $dryRun) {
                    $issue->update($this->attributes($finding) + [
                        'status' => DocumentationAuditIssue::STATUS_OPEN,
                        'last_detected_at' => $detectedAt,
                        'resolved_at' => null,
                    ]);
                }
            }

            $toResolve = $existing
                ->filter(fn (DocumentationAuditIssue $issue): bool => $issue->status === DocumentationAuditIssue::STATUS_OPEN)
                ->reject(fn (DocumentationAuditIssue $issue): bool => in_array($issue->fingerprint, $detected, true));
            $stats['resolved'] = $toResolve->count();

            if (! $dryRun && $toResolve->isNotEmpty()) {
                DocumentationAuditIssue::query()
                    ->whereKey($toResolve->pluck('id'))
                    ->update([
                        'status' => DocumentationAuditIssue::STATUS_RESOLVED,
                        'resolved_at' => $detectedAt,
                        'updated_at' => $detectedAt,
                    ]);
            }

            return $stats;
        };

        return $dryRun ? $synchronize() : DB::transaction($synchronize, 3);
    }

    private function scope(string $period, array $ruleCodes, ?int $patientId, ?int $companyId): Builder
    {
        return DocumentationAuditIssue::query()
            ->where('period', $period)
            ->whereIn('rule_code', $ruleCodes)
            ->when($patientId, fn (Builder $query): Builder => $query->where('patient_id', $patientId))
            ->when($companyId, fn (Builder $query): Builder => $query->where('company_id', $companyId));
    }

    /** @return array<string, mixed> */
    private function attributes(DocumentationAuditFinding $finding): array
    {
        return [
            'company_id' => $finding->companyId,
            'branch_id' => $finding->branchId,
            'patient_id' => $finding->patientId,
            'user_id' => $finding->userId,
            'period' => $finding->period,
            'rule_code' => $finding->ruleCode,
            'severity' => (string) config("documentation_audit.rules.{$finding->ruleCode}.severity", 'error'),
            'date' => $finding->date,
            'procedure_id' => $finding->procedureId,
            'patient_point_id' => $finding->patientPointId,
            'document_id' => $finding->documentId,
            'metadata' => $finding->metadata,
        ];
    }
}