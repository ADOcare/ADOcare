<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\PatientAuditIssue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Detect and reconcile patient data-quality issues.
 */
class PatientAuditService
{
    /**
     * Create the audit service.
     */
    public function __construct(
        private PatientPersonalNumberValidator $personalNumberValidator,
        private PatientAuditNotificationService $notifications,
    ) {}

    /**
     * Audit normal (non-soft-deleted) patients and reconcile their current issues.
     *
     * @return array{patients_checked: int, issues_found: int, opened: int, reopened: int, still_open: int, resolved: int}
     */
    public function run(): array
    {
        $duplicatePatientIds = $this->duplicatePatientIds();
        $stats = [
            'patients_checked' => 0,
            'issues_found' => 0,
            'opened' => 0,
            'reopened' => 0,
            'still_open' => 0,
            'resolved' => 0,
        ];
        $today = CarbonImmutable::today();

        Patient::query()
            ->select([
                'id',
                'first_name',
                'last_name',
                'personal_number',
                'address',
                'city',
                'zip',
                'latitude',
                'longitude',
                'death_date',
                'branch_id',
            ])
            ->chunkById(500, function (Collection $patients) use (&$stats, $duplicatePatientIds, $today): void {
                $patientIds = $patients->modelKeys();
                $findings = [];

                foreach ($patients as $patient) {
                    $stats['patients_checked']++;
                    foreach ($this->issuesFor($patient, $duplicatePatientIds[$patient->id] ?? [], $today) as $fingerprint => $issue) {
                        $findings[$fingerprint] = $issue;
                    }
                }

                $stats['issues_found'] += count($findings);
                $batchStats = $this->reconcile($patientIds, $findings);
                foreach ($batchStats as $key => $value) {
                    $stats[$key] += $value;
                }

                $this->notifications->synchronize(collect($patientIds));
            });

        $stats['resolved'] += PatientAuditIssue::query()
            ->where('status', PatientAuditIssue::STATUS_OPEN)
            ->whereDoesntHave('patient')
            ->update([
                'status' => PatientAuditIssue::STATUS_RESOLVED,
                'resolved_at' => now(),
                'updated_at' => now(),
            ]);

        return $stats;
    }

    /**
     * Find duplicate normalized personal numbers per company.
     *
     * @return array<int, array<int, int>>
     */
    private function duplicatePatientIds(): array
    {
        $groups = [];

        Patient::query()
            ->select(['id', 'branch_id', 'personal_number'])
            ->with('branch:id,company_id')
            ->chunkById(500, function (Collection $patients) use (&$groups): void {
                foreach ($patients as $patient) {
                    $companyId = $patient->branch?->company_id;
                    $normalized = $this->personalNumberValidator->normalize($patient->personal_number);
                    if ($companyId === null || $normalized === null) {
                        continue;
                    }

                    $groups[$companyId.':'.$normalized][] = (int) $patient->id;
                }
            });

        $patientDuplicateIds = [];
        foreach ($groups as $patientIds) {
            if (count($patientIds) < 2) {
                continue;
            }

            sort($patientIds);
            foreach ($patientIds as $patientId) {
                $patientDuplicateIds[$patientId] = $patientIds;
            }
        }

        return $patientDuplicateIds;
    }

    /**
     * @param  array<int, int>  $duplicatePatientIds
     * @return array<string, array{patient_id: int, code: string, severity: string, message: string, metadata: array<string, mixed>}>
     */
    private function issuesFor(Patient $patient, array $duplicatePatientIds, CarbonImmutable $today): array
    {
        $issues = [];
        $rawPersonalNumber = (string) $patient->personal_number;

        if (trim($rawPersonalNumber) === '') {
            $this->addIssue($issues, $patient, 'PAT-001', PatientAuditIssue::SEVERITY_ERROR, 'Pacient nemá vyplnené rodné číslo.');
        } else {
            $validation = $this->personalNumberValidator->validate($rawPersonalNumber);
            if (! $validation['valid']) {
                $this->addIssue(
                    $issues,
                    $patient,
                    'PAT-002',
                    PatientAuditIssue::SEVERITY_ERROR,
                    'Pacient má neplatné rodné číslo.',
                    ['personal_number' => $rawPersonalNumber, 'reason' => $validation['reason']],
                );
            }
        }

        if ($duplicatePatientIds !== []) {
            $this->addIssue(
                $issues,
                $patient,
                'PAT-003',
                PatientAuditIssue::SEVERITY_ERROR,
                'Rodné číslo je použité pri viacerých pacientoch.',
                ['duplicate_patient_ids' => $duplicatePatientIds],
            );
        }

        if (trim((string) $patient->address) === '') {
            $this->addIssue($issues, $patient, 'PAT-004', PatientAuditIssue::SEVERITY_WARNING, 'Pacient nemá vyplnenú adresu.');
        }
        if (trim((string) $patient->city) === '') {
            $this->addIssue($issues, $patient, 'PAT-005', PatientAuditIssue::SEVERITY_WARNING, 'Pacient nemá vyplnené mesto.');
        }
        if (trim((string) $patient->zip) === '') {
            $this->addIssue($issues, $patient, 'PAT-006', PatientAuditIssue::SEVERITY_WARNING, 'Pacient nemá vyplnené PSČ.');
        }

        $latitude = $patient->latitude;
        $longitude = $patient->longitude;
        if ($latitude === null || $longitude === null) {
            $this->addIssue($issues, $patient, 'PAT-007', PatientAuditIssue::SEVERITY_WARNING, 'Pacient nemá kompletné GPS súradnice.');
        }

        if (
            ($latitude !== null && ((float) $latitude < -90 || (float) $latitude > 90))
            || ($longitude !== null && ((float) $longitude < -180 || (float) $longitude > 180))
        ) {
            $this->addIssue($issues, $patient, 'PAT-008', PatientAuditIssue::SEVERITY_ERROR, 'Pacient má neplatné GPS súradnice.');
        }

        if ($patient->death_date !== null && CarbonImmutable::parse($patient->death_date)->startOfDay()->gt($today)) {
            $this->addIssue($issues, $patient, 'PAT-009', PatientAuditIssue::SEVERITY_ERROR, 'Pacient má dátum úmrtia v budúcnosti.');
        }

        return $issues;
    }

    /**
     * @param  array<string, array{patient_id: int, code: string, severity: string, message: string, metadata: array<string, mixed>}>  $issues
     * @param  array<string, mixed>  $metadata
     */
    private function addIssue(
        array &$issues,
        Patient $patient,
        string $code,
        string $severity,
        string $message,
        array $metadata = [],
    ): void {
        $fingerprint = "patient:{$patient->id}:{$code}";
        $issues[$fingerprint] = [
            'patient_id' => (int) $patient->id,
            'code' => $code,
            'severity' => $severity,
            'message' => $message,
            'metadata' => $metadata,
        ];
    }

    /**
     * @param  array<int, int>  $patientIds
     * @param  array<string, array{patient_id: int, code: string, severity: string, message: string, metadata: array<string, mixed>}>  $findings
     * @return array{opened: int, reopened: int, still_open: int, resolved: int}
     */
    private function reconcile(array $patientIds, array $findings): array
    {
        if ($patientIds === []) {
            return ['opened' => 0, 'reopened' => 0, 'still_open' => 0, 'resolved' => 0];
        }

        return DB::transaction(function () use ($patientIds, $findings): array {
            $existing = PatientAuditIssue::query()
                ->whereIn('patient_id', $patientIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('fingerprint');
            $detectedAt = now();
            $detectedFingerprints = array_keys($findings);
            $stats = ['opened' => 0, 'reopened' => 0, 'still_open' => 0, 'resolved' => 0];

            foreach ($findings as $fingerprint => $finding) {
                $issue = $existing->get($fingerprint);
                if ($issue === null) {
                    PatientAuditIssue::query()->create($finding + [
                        'fingerprint' => $fingerprint,
                        'status' => PatientAuditIssue::STATUS_OPEN,
                        'detected_at' => $detectedAt,
                    ]);
                    $stats['opened']++;

                    continue;
                }

                if ($issue->status === PatientAuditIssue::STATUS_RESOLVED) {
                    $stats['reopened']++;
                } else {
                    $stats['still_open']++;
                }

                $issue->update($finding + [
                    'status' => PatientAuditIssue::STATUS_OPEN,
                    'detected_at' => $detectedAt,
                    'resolved_at' => null,
                ]);
            }

            $toResolve = $existing
                ->filter(fn (PatientAuditIssue $issue): bool => $issue->status === PatientAuditIssue::STATUS_OPEN)
                ->reject(fn (PatientAuditIssue $issue): bool => in_array($issue->fingerprint, $detectedFingerprints, true));

            if ($toResolve->isNotEmpty()) {
                PatientAuditIssue::query()
                    ->whereKey($toResolve->modelKeys())
                    ->update([
                        'status' => PatientAuditIssue::STATUS_RESOLVED,
                        'resolved_at' => $detectedAt,
                        'updated_at' => $detectedAt,
                    ]);
                $stats['resolved'] = $toResolve->count();
            }

            return $stats;
        }, 3);
    }
}
