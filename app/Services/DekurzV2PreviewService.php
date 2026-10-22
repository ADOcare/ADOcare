<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\User;

class DekurzV2PreviewService
{
    public function __construct(
        private DekurzBuilderService $builder,
        private DekurzDocumentService $legacyDocuments,
    ) {}

    /** @return array<string, mixed> */
    public function build(
        Patient $patient,
        string $month,
        User $actor,
        ?int $dekurzNumber = null,
        ?array $previewDays = null,
    ): array {
        $patient->loadMissing(['branch.company', 'insuranceCompany']);
        $builder = $this->builder->build($patient, $month);
        $sourceDays = $previewDays ?? $builder['draft']['days'] ?? $builder['days'] ?? [];
        $dailyTexts = collect($sourceDays)->mapWithKeys(function (array $day): array {
            $parts = collect($day['entries'] ?? [])
                ->reject(fn (array $entry): bool => (bool) ($entry['is_excluded'] ?? false))
                ->pluck('text')
                ->map(fn ($text): string => trim((string) $text))
                ->filter()
                ->values();
            $generalNote = trim((string) ($day['general_note'] ?? ''));

            if ($generalNote !== '') {
                $parts->push($generalNote);
            }

            return [(string) $day['date'] => $parts->implode("\n\n")];
        })->all();
        $branchId = (int) ($builder['patient']['branch_id'] ?? $patient->branch_id);
        $days = $this->legacyDocuments->attachVisitTimesForPatient(
            $dailyTexts,
            (int) $patient->id,
            (int) $actor->id,
            $branchId,
        );
        $company = $patient->branch?->company;

        return [
            'schema_version' => 2,
            'source' => 'dekurz_v2_preview',
            'user_id' => $actor->id,
            'user_name' => trim(($actor->title ? $actor->title.' ' : '').($actor->first_name ?? '').' '.($actor->last_name ?? '')),
            'company_id' => $company?->id,
            'company_name' => $company?->name ?? '',
            'company_address' => collect([$company?->address, $company?->city])->filter()->implode(', '),
            'branch_id' => $branchId,
            'insurance_code' => $patient->insuranceCompany?->branch_code ?? '',
            'patient_id' => $patient->id,
            'patient_name' => trim(($patient->title ? $patient->title.' ' : '').($patient->first_name ?? '').' '.($patient->last_name ?? '')),
            'patient_personal_number' => $patient->personal_number,
            'patient_birth_number' => $patient->personal_number,
            'patient_address' => collect([$patient->address, $patient->city, $patient->zip])->filter()->implode(', '),
            'dekurz_number' => $dekurzNumber ?? max(1, (int) $patient->dekurz_number),
            'month' => $month,
            'period' => $month,
            'days' => $days,
        ];
    }
}
