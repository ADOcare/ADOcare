<?php

namespace App\Services\DocumentationAudit\Rules;

use App\Contracts\DocumentationAuditRule;
use App\Models\PatientPoint;
use App\Services\DocumentationAudit\DocumentationAuditContext;
use App\Services\DocumentationAudit\DocumentationAuditFinding;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class CheckMedicalFindingReferenceDateRule implements DocumentationAuditRule
{
    public function code(): string
    {
        return 'DOC-006';
    }

    public function audit(DocumentationAuditContext $context): Collection
    {
        $pointsByReferenceDate = $context->points
            ->filter(fn (PatientPoint $point): bool => $point->reference_date !== null)
            ->groupBy(fn (PatientPoint $point): string => CarbonImmutable::parse($point->reference_date)->toDateString());
        $coveredReferenceDates = $context->documentsOfType('scan')
            ->filter(fn ($document): bool => $document->reference_date !== null)
            ->map(fn ($document): string => CarbonImmutable::parse($document->reference_date)->toDateString())
            ->unique();

        return $pointsByReferenceDate
            ->reject(fn (Collection $points, string $referenceDate): bool => $coveredReferenceDates->contains($referenceDate))
            ->map(function (Collection $points, string $referenceDate) use ($context): DocumentationAuditFinding {
                $points = $points->sortBy('id')->values();
                $pointIds = $points->pluck('id')->map(fn ($id): int => (int) $id)->values();
                $affectedDates = $points
                    ->pluck('date')
                    ->filter()
                    ->map(fn ($date): string => CarbonImmutable::parse($date)->toDateString())
                    ->unique()
                    ->sort()
                    ->values();
                $branchIds = $points->pluck('branch_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values();
                $userIds = $points->pluck('user_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values();
                $companyIds = $points
                    ->map(fn (PatientPoint $point): ?int => $context->companyIdFor($point))
                    ->filter()
                    ->unique()
                    ->values();

                return new DocumentationAuditFinding(
                    ruleCode: $this->code(),
                    companyId: $companyIds->count() === 1 ? $companyIds->first() : null,
                    branchId: $branchIds->count() === 1 ? $branchIds->first() : null,
                    patientId: (int) $context->patient->id,
                    userId: $userIds->count() === 1 ? $userIds->first() : null,
                    period: $context->period,
                    date: $referenceDate,
                    procedureId: null,
                    patientPointId: null,
                    documentId: null,
                    metadata: [
                        'reference_date' => $referenceDate,
                        'affected_points_count' => $points->count(),
                        'patient_point_ids' => $pointIds->take(100)->all(),
                        'patient_point_ids_truncated' => $pointIds->count() > 100,
                        'affected_dates' => $affectedDates->all(),
                        'first_affected_date' => $affectedDates->first(),
                        'last_affected_date' => $affectedDates->last(),
                        'procedure_codes' => $points->pluck('procedure_code')->filter()->unique()->sort()->values()->all(),
                        'user_ids' => $userIds->all(),
                    ],
                    fingerprintDiscriminator: "reference_date:{$referenceDate}",
                );
            })
            ->values();
    }
}