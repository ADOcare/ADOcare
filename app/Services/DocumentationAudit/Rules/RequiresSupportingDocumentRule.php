<?php

namespace App\Services\DocumentationAudit\Rules;

use App\Contracts\DocumentationAuditRule;
use App\Models\PatientPoint;
use App\Services\DocumentationAudit\DocumentationAuditContext;
use App\Services\DocumentationAudit\DocumentationAuditFinding;
use Illuminate\Support\Collection;

abstract class RequiresSupportingDocumentRule implements DocumentationAuditRule
{
    public function audit(DocumentationAuditContext $context): Collection
    {
        $settings = (array) config("documentation_audit.rules.{$this->code()}", []);
        $codes = (array) ($settings['procedure_codes'] ?? []);
        $type = (string) ($settings['document_type'] ?? '');
        $samePeriod = (bool) ($settings['match_period'] ?? false);

        return $context->pointsWithCodes($codes)
            ->reject(fn (PatientPoint $point): bool => $context->closestDocument($type, $point, $samePeriod) !== null)
            ->map(fn (PatientPoint $point): DocumentationAuditFinding => DocumentationAuditFinding::forPoint(
                $this->code(),
                $context,
                $point,
                [
                    'required_document_type' => $type,
                    'date_matching' => $samePeriod ? 'same_period' : 'existence',
                ],
            ))
            ->values();
    }
}