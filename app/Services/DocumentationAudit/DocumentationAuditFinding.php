<?php

namespace App\Services\DocumentationAudit;

use App\Models\PatientPoint;
use Carbon\CarbonImmutable;

readonly class DocumentationAuditFinding
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $ruleCode,
        public ?int $companyId,
        public ?int $branchId,
        public int $patientId,
        public ?int $userId,
        public string $period,
        public ?string $date,
        public ?int $procedureId,
        public ?int $patientPointId,
        public ?int $documentId,
        public array $metadata,
        public ?string $fingerprintDiscriminator = null,
    ) {
    }

    /** @param array<string, mixed> $metadata */
    public static function forPoint(
        string $ruleCode,
        DocumentationAuditContext $context,
        PatientPoint $point,
        array $metadata = [],
    ): self {
        return new self(
            ruleCode: $ruleCode,
            companyId: $context->companyIdFor($point),
            branchId: $point->branch_id ? (int) $point->branch_id : null,
            patientId: (int) $point->patient_id,
            userId: $point->user_id ? (int) $point->user_id : null,
            period: $context->period,
            date: $point->date ? CarbonImmutable::parse($point->date)->toDateString() : null,
            procedureId: $point->procedure_id ? (int) $point->procedure_id : null,
            patientPointId: (int) $point->id,
            documentId: null,
            metadata: array_merge([
                'procedure_code' => $point->procedure_code,
                'procedure_description' => $point->procedure?->description,
                'point_date' => $point->date ? CarbonImmutable::parse($point->date)->toDateString() : null,
                'quantity' => (int) $point->quantity,
                'patient_point_id' => (int) $point->id,
            ], $metadata),
        );
    }

    public function fingerprint(): string
    {
        $subject = $this->patientPointId !== null
            ? (string) $this->patientPointId
            : ($this->fingerprintDiscriminator ?? 'none');

        return hash('sha256', implode('|', [
            $this->ruleCode,
            $this->patientId,
            $this->period,
            $subject,
        ]));
    }
}