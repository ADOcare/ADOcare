<?php

namespace App\Services\DocumentationAudit;

use App\Models\Document;
use App\Models\PatientPoint;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ImmediateDocumentationAuditService
{
    /** @var array<int, string> */
    private const IMMEDIATE_RULES = ['DOC-002', 'DOC-003', 'DOC-004', 'DOC-005', 'DOC-006'];

    /** @var array<string, array{rule: string, code?: string}> */
    private const DOCUMENT_RULES = [
        'agreement' => ['rule' => 'DOC-002', 'code' => '3390'],
        'record' => ['rule' => 'DOC-003', 'code' => '3390'],
        'proposal' => ['rule' => 'DOC-004', 'code' => '3393'],
        'leave' => ['rule' => 'DOC-005', 'code' => '3395'],
        'scan' => ['rule' => 'DOC-006'],
    ];

    public function __construct(private DocumentationAuditService $audit)
    {
    }

    /** @param array<string, mixed>|null $previous */
    public function patientPointChanged(PatientPoint $point, ?array $previous = null): void
    {
        $targets = [[
            'patient_id' => $point->patient_id,
            'date' => $point->date,
        ]];

        if ($previous !== null) {
            $targets[] = [
                'patient_id' => $previous['patient_id'] ?? null,
                'date' => $previous['date'] ?? null,
            ];
        }

        $this->runTargets(collect($targets), self::IMMEDIATE_RULES);
    }

    /** @param iterable<int, PatientPoint> $points */
    public function patientPointsChanged(iterable $points): void
    {
        $targets = collect($points)->map(fn (PatientPoint $point): array => [
            'patient_id' => $point->patient_id,
            'date' => $point->date,
        ]);

        $this->runTargets($targets, self::IMMEDIATE_RULES);
    }

    /** @param iterable<int, Document> $documents */
    public function documentsChanged(iterable $documents): void
    {
        $targetsByRule = [];

        foreach ($documents as $document) {
            $settings = self::DOCUMENT_RULES[(string) $document->type] ?? null;
            $patientId = (int) $document->patient_id;

            if ($settings === null || $patientId <= 0) {
                continue;
            }

            $query = PatientPoint::query()
                ->where('patient_id', $patientId)
                ->whereNotNull('date');

            if ($document->type === 'scan') {
                if ($document->reference_date === null) {
                    continue;
                }

                $query->whereDate('reference_date', CarbonImmutable::parse($document->reference_date)->toDateString());
            } else {
                $query->where('procedure_code', $settings['code']);

                if ($document->type !== 'agreement') {
                    $this->scopePeriod($query, (string) $document->period);
                }
            }

            $periods = $query
                ->pluck('date')
                ->map(fn ($date): string => CarbonImmutable::parse($date)->format('Y-m'))
                ->unique();

            foreach ($periods as $period) {
                $targetsByRule[$settings['rule']][] = [
                    'patient_id' => $patientId,
                    'date' => "{$period}-01",
                ];
            }
        }

        foreach ($targetsByRule as $ruleCode => $targets) {
            $this->runTargets(collect($targets), [$ruleCode]);
        }
    }

    public function recheckPatientPeriod(int $patientId, string $period): void
    {
        $this->audit->runImmediate($period, $patientId, self::IMMEDIATE_RULES);
    }

    /** @param Collection<int, array{patient_id: mixed, date: mixed}> $targets @param array<int, string> $rules */
    private function runTargets(Collection $targets, array $rules): void
    {
        $targets
            ->filter(fn (array $target): bool => (int) $target['patient_id'] > 0 && $target['date'] !== null)
            ->map(fn (array $target): array => [
                'patient_id' => (int) $target['patient_id'],
                'period' => CarbonImmutable::parse($target['date'])->format('Y-m'),
            ])
            ->unique(fn (array $target): string => $target['patient_id'].'|'.$target['period'])
            ->each(fn (array $target) => $this->audit->runImmediate(
                $target['period'],
                $target['patient_id'],
                $rules,
            ));
    }

    private function scopePeriod(Builder $query, string $period): void
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            $query->whereRaw('1 = 0');
            return;
        }

        $start = CarbonImmutable::createFromFormat('!Y-m', $period)->startOfMonth();
        $query->whereBetween('date', [$start->toDateString(), $start->endOfMonth()->toDateString()]);
    }
}