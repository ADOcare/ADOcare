<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use App\Models\Branch;
use Illuminate\Support\Str;
use App\Services\Claims\InsuredClaimResolver;


class KilometersBatchDocumentService
{
    public function __construct(
        private PointsBatchNumberService $batchNumberService,
        private InsuredClaimResolver $insuredResolver,
    ) {
    }

    public function getKilometersBatchPayload(Document $document): ?array
    {
        if (! $document->path || ! Storage::disk('local')->exists($document->path)) {
            return null;
        }

        return json_decode(Storage::disk('local')->get($document->path), true);
    }


    public function createKilometersBatch(array $data, $actor): array
    {
        $insuranceId = (int) data_get($data, 'insurance.id');
        $branchId  = (int) data_get($data, 'branch.id');
        $branch = Branch::query()->findOrFail($branchId);
        abort_unless($actor && $actor->isInBranch($branchId), 403);
        $companyId = (int) $branch->company_id;

        $periodFromRaw = (string) data_get($data, 'period.0');
        $periodToRaw   = (string) data_get($data, 'period.1');

        $subtype = (string) data_get($data, 'batchType.code', 'N');
        $batchNumber = $this->batchNumberService->make($actor, $insuranceId, $periodFromRaw);

        $tz = 'Europe/Bratislava';
        $to = Carbon::parse($periodToRaw)->setTimezone($tz);

        $periodKey = $to->format('Y-m');

        $this->validateKilometersBatchData(
            data: $data,
            actorId: (int) $actor->id,
            branchId: $branchId,
            companyId: $companyId,
            insuranceId: $insuranceId,
            periodFromRaw: $periodFromRaw,
            periodToRaw: $periodToRaw,
        );

        return DB::transaction(function () use (
            $data, $actor, $branchId, $companyId, $periodFromRaw, $periodToRaw, $periodKey, $subtype, $insuranceId,
            $batchNumber,
        ) {
            $type = 'kilometers_batch';

            $isNewBatch = in_array($subtype, ['N', 'E', 'I'], true);
            $existing = $isNewBatch ? Document::query()
                ->where('type', $type)
                ->where('subtype', $subtype)
                ->where('user_id', $actor->id)
                ->where('branch_id', $branchId)
                ->where('period', $periodKey)
                ->where('insurance_company_id', $insuranceId)
                ->lockForUpdate()
                ->first() : null;

            $newPath = 'kilometers_batches/' . Str::uuid() . '_' . $batchNumber . '.json';

            if ($existing) {
                if ($existing->path && Storage::disk('local')->exists($existing->path)) {
                    Storage::disk('local')->delete($existing->path);
                }

                $existing->update([
                    'company_id' => $companyId ?: $existing->company_id,
                    'mime_type'  => 'application/json',
                    'name'       => 'kilometre_' . $subtype . '_davka_' . $batchNumber . '_' . now()->format('d.m.Y'),
                    'path'       => $newPath,
                    'period'     => $periodKey,
                    'subtype'    => $subtype,
                    'insurance_company_id' => $insuranceId,
                ]);

                $document = $existing;
            } else {
                $document = Document::create([
                    'patient_id' => null,
                    'branch_id'  => $branchId,
                    'company_id' => $companyId ?: null,
                    'user_id'    => $actor->id,
                    'insurance_company_id' => $insuranceId,


                    'type'       => $type,
                    'subtype'    => $subtype,
                    'mime_type'  => 'application/json',

                    'name'       => 'kilometre_' . $subtype . '_davka_' . $batchNumber . '_' . now()->format('d.m.Y'),
                    'path'       => $newPath,
                    'period'     => $periodKey,
                ]);
            }

            $payload = [
                'document_id' => $document->id,
                'batchNumber' => $batchNumber,
                'batchType'   => ['code' => $subtype],
                'insurance'   => ['id' => (int) data_get($data, 'insurance.id')],
                'period'      => [$periodFromRaw, $periodToRaw],
                'user'        => ['id' => $actor->id],
                'branch'      => ['id' => $branchId],
                'company'     => ['id' => $companyId ?: null],
                'patients'    => collect(data_get($data, 'patients', []))
                    ->map(fn ($p) => ['id' => (int) data_get($p, 'id')])
                    ->values()
                    ->all(),
                'meta' => data_get($data, 'meta', []),
                'saved_at' => now(),
            ];

            Storage::disk('local')->put(
                $document->path,
                json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );

            return [$document, $payload];
        });
    }

    private function validateKilometersBatchData(
        array $data,
        int $actorId,
        int $branchId,
        int $companyId,
        int $insuranceId,
        string $periodFromRaw,
        string $periodToRaw,
    ): void {
        $from = Carbon::parse($periodFromRaw)->setTimezone('Europe/Bratislava')->toDateString();
        $to = Carbon::parse($periodToRaw)->setTimezone('Europe/Bratislava')->toDateString();

        $patientIds = collect(data_get($data, 'patients', []))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values()
            ->all();

        $rows = DB::table('patient_points as pp')
            ->join('patients as p', 'p.id', '=', 'pp.patient_id')
            ->join('patient_coverages as pc', function ($join) {
                $join->on('pc.patient_id', '=', 'p.id')
                    ->where(function ($query) {
                        $query->whereNull('pc.valid_from')
                            ->orWhereColumn('pc.valid_from', '<=', 'pp.date');
                    })
                    ->where(function ($query) {
                        $query->whereNull('pc.valid_to')
                            ->orWhereColumn('pc.valid_to', '>=', 'pp.date');
                    });
            })
            ->join('doctors as d', 'd.id', '=', 'p.doctor_id')
            ->join('branches as b', 'b.id', '=', 'pp.branch_id')
            ->whereColumn('p.nurse_id', 'pp.user_id')
            ->whereColumn('p.branch_id', 'pp.branch_id')
            ->join('procedures as proc', function ($join) {
                $join->where('proc.code', '0000');
            })
            ->join('procedure_company_prices as pcp', function ($join) {
                $join->on('pcp.procedure_id', '=', 'proc.id')
                    ->on('pcp.insurance_company_id', '=', 'pc.insurance_company_id');
            })
            ->where('pcp.company_id', $companyId)
            ->where('pp.user_id', $actorId)
            ->where('pp.branch_id', $branchId)
            ->where('pc.insurance_company_id', $insuranceId)
            ->where('pc.regime', $this->regimeForCharacter((string) data_get($data, 'batchType.code')))
            ->whereBetween('pp.date', [$from, $to])
            ->whereIn('pp.procedure_code', ['3439', '3440'])
            ->when(!empty($patientIds), fn($q) => $q->whereIn('pp.patient_id', $patientIds))
            ->select([
                'pp.id',
                'pp.date',
                'pp.patient_id',
                'p.personal_number',
                'p.last_name',
                'p.first_name',
                'p.sex',
                'p.city as patient_city',
                'p.address as patient_address',
                'p.latitude as patient_lat',
                'p.longitude as patient_lng',
                'pp.diagnosis_code',
                'pp.procedure_code',
                DB::raw('COALESCE(pp.doctor_pzs, d.pzs) as doctor_pzs'),
                DB::raw('COALESCE(pp.doctor_zpr, d.zpr) as doctor_zpr'),
                'b.city as branch_city',
                'b.address as branch_address',
                'b.latitude as branch_lat',
                'b.longitude as branch_lng',
                'pcp.price',
                'pc.regime',
                'pc.identification_method',
                'pc.member_state_code',
                'pc.foreign_insured_id',
                'pc.special_category',
                'pc.entitlement_document_type',
                'pc.entitlement_confirmed',
                'pc.valid_from as coverage_valid_from',
            ])
            ->orderBy('pp.date')
            ->orderBy('pp.patient_id')
            ->orderBy('pp.id')
            ->orderByDesc('pc.valid_from')
            ->get()
            ->unique('id')
            ->values();

        $this->validateRowsForBatchCreation($rows, (string) data_get($data, 'batchType.code'));
    }

    private function validateRowsForBatchCreation($rows, string $character): void
    {
        $errors = [];

        if ($rows->isEmpty()) {
            $errors[] = 'Nenašli sa žiadne výkony pre zadané filtre.';
        }

        foreach ($rows as $row) {
            $patientName = $this->formatPatientName($row);
            $resolved = $this->insuredResolver->resolve(
                $row,
                $this->insuredResolver->operationForCharacter($character)
            );

            foreach ($resolved->errors as $resolverError) {
                $errors[] = "{$resolverError} Pacient: {$patientName}.";
            }

            if ($resolved->character !== $character) {
                $errors[] = "Pacient {$patientName} patrí do dávky {$resolved->character}, nie {$character}.";
            }
            $this->addMissingError($errors, $row->last_name, "Chýba priezvisko pacienta {$patientName}.");
            $this->addMissingError($errors, $row->first_name, "Chýba meno pacienta {$patientName}.");
            $this->addMissingError($errors, $row->sex, "Chýba pohlavie pacienta {$patientName}.");

            $this->addMissingError($errors, $row->patient_city, "Chýba mesto pacienta {$patientName}.");
            $this->addMissingError($errors, $row->patient_address, "Chýba adresa pacienta {$patientName}.");

            $this->addMissingError($errors, $row->patient_lat, "Chýba GPS latitude pacienta {$patientName}.");
            $this->addMissingError($errors, $row->patient_lng, "Chýba GPS longitude pacienta {$patientName}.");

            $this->addMissingError($errors, $row->diagnosis_code, "Chýba diagnóza pri pacientovi {$patientName}.");
            $this->addMissingError($errors, $row->procedure_code, "Chýba kód výkonu pri pacientovi {$patientName}.");

            $this->addMissingError($errors, $row->doctor_pzs, "Chýba PZS lekára pri pacientovi {$patientName}.");
            $this->addMissingError($errors, $row->doctor_zpr, "Chýba ZPR lekára pri pacientovi {$patientName}.");

            $this->addMissingError($errors, $row->branch_city, "Chýba mesto prevádzky pri pacientovi {$patientName}.");
            $this->addMissingError($errors, $row->branch_address, "Chýba adresa prevádzky pri pacientovi {$patientName}.");
            $this->addMissingError($errors, $row->branch_lat, "Chýba GPS latitude prevádzky pri pacientovi {$patientName}.");
            $this->addMissingError($errors, $row->branch_lng, "Chýba GPS longitude prevádzky pri pacientovi {$patientName}.");

            $this->addMissingError($errors, $row->price, "Chýba cena výkonu 0000 pre pacienta {$patientName}.");
        }

        if (in_array($character, ['I', 'J', 'K'], true)) {
            $specialCategories = $rows->pluck('special_category')->filter()->unique();

            if ($specialCategories->count() > 1) {
                $errors[] = 'Osobitná dávka nesmie miešať rozdielne právne kategórie poistencov.';
            }
        }

        $this->throwKilometersValidationErrors($errors);
    }

    private function regimeForCharacter(string $character): string
    {
        return match (true) {
            in_array($character, ['N', 'O', 'A'], true) => 'domestic',
            in_array($character, ['E', 'F', 'G'], true) => 'eu',
            in_array($character, ['I', 'J', 'K'], true) => 'special',
            default => 'unclassified',
        };
    }

    private function addMissingError(array &$errors, mixed $value, string $message): void
    {
        if (!$this->isFilledValue($value)) {
            $errors[] = $message;
        }
    }

    private function isFilledValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value) && trim($value) === '') {
            return false;
        }

        return true;
    }

    private function formatPatientName(object $row): string
    {
        $name = trim(($row->last_name ?? '') . ' ' . ($row->first_name ?? ''));

        if ($name !== '') {
            return $name;
        }

        if (!empty($row->patient_id)) {
            return "#{$row->patient_id}";
        }

        return 'neznámy pacient';
    }

    private function throwKilometersValidationErrors(array $errors): void
    {
        $errors = array_values(array_unique($errors));

        if (!$errors) {
            return;
        }

        throw ValidationException::withMessages([
            'kilometers_export' => $errors,
        ]);
    }
}
