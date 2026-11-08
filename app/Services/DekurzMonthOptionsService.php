<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Patient;
use App\Models\PatientPoint;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class DekurzMonthOptionsService
{
    public const FIRST_SUPPORTED_MONTH = '2026-08';

    public function __construct(
        private DekurzTimelineReadinessService $timelineReadiness,
        private DekurzProcedureClassifier $classifier,
    ) {}

    /**
     * @return list<array{
     *     month: string,
     *     label: string,
     *     status: 'issued'|'ready'|'timeline_required',
     *     document_id: int|null,
     *     message: string|null
     * }>
     */
    public function forPatient(Patient $patient, User $actor): array
    {
        $pointsByMonth = PatientPoint::query()
            ->where('patient_id', $patient->id)
            ->whereDate('date', '>=', self::FIRST_SUPPORTED_MONTH.'-01')
            ->whereIn('procedure_code', $this->classifier->documentableCodes())
            ->orderBy('date')
            ->get()
            ->groupBy(fn (PatientPoint $point): string => Carbon::parse($point->date)->format('Y-m'));

        $documentsByMonth = Document::query()
            ->where('patient_id', $patient->id)
            ->where('type', 'dekurz')
            ->whereNotNull('period')
            ->where('period', '>=', self::FIRST_SUPPORTED_MONTH)
            ->orderByDesc('id')
            ->get()
            ->unique(fn (Document $document): string => substr((string) $document->period, 0, 7))
            ->keyBy(fn (Document $document): string => substr((string) $document->period, 0, 7));

        return $pointsByMonth->keys()
            ->merge($documentsByMonth->keys())
            ->unique()
            ->sortDesc()
            ->map(function (string $month) use ($patient, $actor, $pointsByMonth, $documentsByMonth): array {
                $document = $documentsByMonth->get($month);
                if ($document) {
                    return $this->option($month, 'issued', (int) $document->id);
                }

                try {
                    $this->timelineReadiness->assertReady(
                        $patient,
                        $actor,
                        $pointsByMonth->get($month, collect()),
                        $month,
                    );

                    return $this->option($month, 'ready');
                } catch (ValidationException $exception) {
                    return $this->option(
                        $month,
                        'timeline_required',
                        message: collect($exception->errors())->flatten()->first(),
                    );
                }
            })
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     month: string,
     *     label: string,
     *     status: 'issued'|'ready'|'timeline_required',
     *     document_id: int|null,
     *     message: string|null
     * }
     */
    private function option(
        string $month,
        string $status,
        ?int $documentId = null,
        ?string $message = null,
    ): array {
        return [
            'month' => $month,
            'label' => Carbon::createFromFormat('!Y-m', $month)->translatedFormat('m/Y'),
            'status' => $status,
            'document_id' => $documentId,
            'message' => $message,
        ];
    }
}
