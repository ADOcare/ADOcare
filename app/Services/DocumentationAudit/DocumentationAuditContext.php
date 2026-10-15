<?php

namespace App\Services\DocumentationAudit;

use App\Models\Document;
use App\Models\Patient;
use App\Models\PatientPoint;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class DocumentationAuditContext
{
    /** @var array<int, array<string, mixed>|null> */
    private array $payloads = [];

    /** @var array<string, int> */
    private array $diagnostics = [];

    /**
     * @param Collection<int, PatientPoint> $points
     * @param Collection<int, Document> $documents
     */
    public function __construct(
        public readonly Patient $patient,
        public readonly string $period,
        public readonly Collection $points,
        public readonly Collection $documents,
    ) {
    }

    /** @return Collection<int, PatientPoint> */
    public function pointsWithCodes(array $codes): Collection
    {
        return $this->points
            ->filter(fn (PatientPoint $point): bool => in_array((string) $point->procedure_code, $codes, true))
            ->values();
    }

    /** @return Collection<int, Document> */
    public function documentsOfType(string $type, bool $samePeriod = false): Collection
    {
        return $this->documents
            ->filter(fn (Document $document): bool => $document->type === $type
                && (! $samePeriod || $document->period === $this->period))
            ->values();
    }

    public function companyIdFor(PatientPoint $point): ?int
    {
        $companyId = $point->branch?->company_id ?? $this->patient->branch?->company_id;

        return $companyId ? (int) $companyId : null;
    }

    /** @return array<string, mixed>|null */
    public function documentPayload(Document $document): ?array
    {
        if (array_key_exists((int) $document->id, $this->payloads)) {
            return $this->payloads[(int) $document->id];
        }

        if (! $document->path || ! Storage::disk('local')->exists($document->path)) {
            return $this->payloads[(int) $document->id] = null;
        }

        $decoded = json_decode(Storage::disk('local')->get($document->path), true);

        return $this->payloads[(int) $document->id] = is_array($decoded) ? $decoded : null;
    }

    public function closestDocument(string $type, PatientPoint $point, bool $samePeriod = false): ?Document
    {
        return $this->documentsOfType($type, $samePeriod)
            ->sortBy(function (Document $document) use ($point): float {
                $payloadDate = $this->documentPayload($document)['date'] ?? null;
                $documentDate = match (true) {
                    $payloadDate !== null => CarbonImmutable::parse($payloadDate),
                    preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $document->period) === 1
                        => CarbonImmutable::createFromFormat('!Y-m', (string) $document->period)->endOfMonth(),
                    default => CarbonImmutable::parse($document->created_at ?? '1970-01-01'),
                };

                return abs($documentDate->diffInDays(CarbonImmutable::parse($point->date), false));
            })
            ->first();
    }

    public function addDiagnostic(string $key): void
    {
        $this->diagnostics[$key] = ($this->diagnostics[$key] ?? 0) + 1;
    }

    /** @return array<string, int> */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }
}