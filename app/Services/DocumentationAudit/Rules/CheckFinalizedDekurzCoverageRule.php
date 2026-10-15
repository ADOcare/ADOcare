<?php

namespace App\Services\DocumentationAudit\Rules;

use App\Contracts\DocumentationAuditRule;
use App\Models\PatientPoint;
use App\Services\DocumentationAudit\DocumentationAuditContext;
use App\Services\DocumentationAudit\DocumentationAuditFinding;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class CheckFinalizedDekurzCoverageRule implements DocumentationAuditRule
{
    public function code(): string
    {
        return 'DOC-001';
    }

    public function audit(DocumentationAuditContext $context): Collection
    {
        $startDate = config('documentation_audit.rules.DOC-001.start_date');
        $points = $context->pointsWithCodes(config('documentation_audit.documentable_procedure_codes', []))
            ->filter(fn (PatientPoint $point): bool => ! $startDate
                || CarbonImmutable::parse($point->date)->greaterThanOrEqualTo(CarbonImmutable::parse($startDate)))
            ->values();
        $dekurzDocuments = $context->documentsOfType('dekurz', true);
        $coveredPointIds = collect();
        $legacyDocuments = 0;

        foreach ($dekurzDocuments as $document) {
            $snapshot = $context->documentPayload($document);
            if ($snapshot === null) {
                continue;
            }
            if (($snapshot['schema_version'] ?? null) !== 2 || ($snapshot['source'] ?? null) !== 'dekurz_v2') {
                $legacyDocuments++;
                continue;
            }

            foreach ($snapshot['days'] ?? [] as $day) {
                foreach ($day['entries'] ?? [] as $entry) {
                    $pointId = $entry['patient_point_snapshot_id'] ?? null;
                    if ($pointId) {
                        $coveredPointIds->push((int) $pointId);
                        continue;
                    }

                    $matches = $points->filter(fn (PatientPoint $point): bool => $this->matchesLegacyV2Entry($point, $day, $entry));
                    if ($matches->count() === 1) {
                        $coveredPointIds->push((int) $matches->first()->id);
                    }
                }
            }
        }

        return $points
            ->reject(fn (PatientPoint $point): bool => $coveredPointIds->contains((int) $point->id))
            ->map(function (PatientPoint $point) use ($context, $legacyDocuments): ?DocumentationAuditFinding {
                if ($legacyDocuments > 0) {
                    $context->addDiagnostic('legacy_not_structurally_verifiable');
                    return null;
                }

                return DocumentationAuditFinding::forPoint($this->code(), $context, $point, [
                    'verification' => 'missing_from_finalized_v2_snapshot',
                ]);
            })
            ->filter()
            ->values();
    }

    /** @param array<string, mixed> $day @param array<string, mixed> $entry */
    private function matchesLegacyV2Entry(PatientPoint $point, array $day, array $entry): bool
    {
        $procedure = (array) ($entry['procedure'] ?? []);

        return ($point->date ? CarbonImmutable::parse($point->date)->toDateString() : null) === ($day['date'] ?? null)
            && ((int) ($procedure['id'] ?? 0) === (int) $point->procedure_id
                || (string) ($procedure['code'] ?? '') === (string) $point->procedure_code)
            && (int) ($entry['quantity'] ?? 0) === (int) $point->quantity;
    }
}