<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\PatientAuditIssue;
use Illuminate\Support\Collection;

/**
 * Synchronize patient audit findings with the nurse notification centre.
 */
class PatientAuditNotificationService
{
    /**
     * Create the notification synchronizer.
     */
    public function __construct(private PatientNotificationService $notifications) {}

    /**
     * Update persistent notifications for the supplied patients.
     *
     * @param  Collection<int, int>  $patientIds
     */
    public function synchronize(Collection $patientIds): void
    {
        $patientIds = $patientIds->unique()->values();
        $patients = Patient::query()->whereKey($patientIds)->get()->keyBy('id');
        $openIssues = PatientAuditIssue::query()
            ->whereIn('patient_id', $patientIds)
            ->where('status', PatientAuditIssue::STATUS_OPEN)
            ->orderBy('id')
            ->get()
            ->groupBy('patient_id');

        foreach ($patientIds as $patientId) {
            /** @var Patient|null $patient */
            $patient = $patients->get($patientId);
            if (! $patient) {
                continue;
            }

            $issues = $openIssues->get($patientId, collect());
            $key = $this->key((int) $patientId);

            if ($issues->isEmpty()) {
                $this->notifications->resolveIssue($patient, $key);

                continue;
            }

            $this->reportIssues($patient, $issues, $key);
        }
    }

    /**
     * @param  Collection<int, PatientAuditIssue>  $issues
     */
    private function reportIssues(Patient $patient, Collection $issues, string $key): void
    {
        $patientName = trim(implode(' ', array_filter([
            $patient->title,
            $patient->first_name,
            $patient->last_name,
        ])));
        $summaries = $issues
            ->take(3)
            ->map(fn (PatientAuditIssue $issue): string => $issue->message)
            ->values();

        if ($issues->count() > 3) {
            $remaining = $issues->count() - 3;
            $summaries->push('A ďalšie '.$remaining.($remaining === 1 ? ' upozornenie.' : ' upozornenia.'));
        }

        $this->notifications->reportIssueToAssignedNurse(
            $patient,
            $key,
            'patient_audit_issue',
            ($patientName !== '' ? $patientName : 'Pacient').' – skontrolovať údaje',
            $summaries->implode(' '),
            [
                'patient_id' => (int) $patient->id,
                'branch_id' => $patient->branch_id ? (int) $patient->branch_id : null,
                'patient_name' => $patientName,
                'issue_count' => $issues->count(),
                'issue_ids' => $issues->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                'issue_codes' => $issues->pluck('code')->unique()->values()->all(),
                'severity' => $this->highestSeverity($issues->pluck('severity')),
            ],
        );
    }

    private function key(int $patientId): string
    {
        return "patient-audit:patient:{$patientId}";
    }

    /**
     * @param  Collection<int, string>  $severities
     */
    private function highestSeverity(Collection $severities): string
    {
        foreach ([
            PatientAuditIssue::SEVERITY_ERROR,
            PatientAuditIssue::SEVERITY_WARNING,
            PatientAuditIssue::SEVERITY_INFO,
        ] as $severity) {
            if ($severities->contains($severity)) {
                return $severity;
            }
        }

        return (string) ($severities->first() ?? PatientAuditIssue::SEVERITY_INFO);
    }
}
