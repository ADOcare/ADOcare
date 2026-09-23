<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\PointClaimBatch;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PointClaimSelectionService
{
    private const NEW_TYPES = ['N', 'E', 'I'];
    private const CORRECTIVE_TYPES = ['O', 'F', 'J'];
    private const ADDITIVE_TYPES = ['A', 'G', 'K'];

    public function candidates(array $data, User $actor): array
    {
        $context = $this->context($data, $actor);
        $existingBatch = $this->existingNewBatch($context);
        $canRebuildExistingBatch = $existingBatch !== null;

        $rows = $this->queryRows($context);
        $correctableLines = collect();
        $baseNewBatch = null;
        $baseNewPointIds = collect();

        if (in_array($context['batch_type'], self::CORRECTIVE_TYPES, true)) {
            $correctableLines = $this->correctableLines($context);
            $rows = $rows
                ->filter(fn (object $row) => $correctableLines->has((int) $row->patient_point_id))
                ->values();
        } elseif (in_array($context['batch_type'], self::ADDITIVE_TYPES, true)) {
            $baseNewBatch = $this->baseNewBatch($context);

            if (! $baseNewBatch) {
                throw ValidationException::withMessages([
                    'batch_type' => ['Pred aditívnou dávkou musí byť vytvorená aktuálna nová dávka.'],
                ]);
            }

            $baseNewPointIds = $baseNewBatch->lines()
                ->pluck('patient_point_id')
                ->map(fn ($id) => (int) $id)
                ->unique();
        }

        $candidates = $rows->map(function (object $row) use ($context, $correctableLines, $baseNewPointIds) {
            $reasons = $this->blockingReasons($row, $context['batch_type']);
            $eligible = $reasons === [];
            $correctable = $correctableLines->get((int) $row->patient_point_id);
            $wasEdited = (string) $row->created_at !== (string) $row->updated_at;
            $isAdditional = in_array($context['batch_type'], self::ADDITIVE_TYPES, true)
                && ! $baseNewPointIds->contains((int) $row->patient_point_id);

            $suggested = match (true) {
                in_array($context['batch_type'], self::CORRECTIVE_TYPES, true) => $eligible && $wasEdited,
                in_array($context['batch_type'], self::ADDITIVE_TYPES, true) => $eligible && $isAdditional,
                default => $eligible,
            };

            return [
                'point_id' => (int) $row->patient_point_id,
                'previous_claim_line_id' => data_get($correctable, 'line_id'),
                'coverage_id' => (int) $row->coverage_id,
                'patient_id' => (int) $row->patient_id,
                'patient_name' => trim((string) $row->first_name . ' ' . (string) $row->last_name),
                'personal_number' => (string) ($row->personal_number ?? ''),
                'service_date' => (string) $row->date,
                'procedure_code' => (string) ($row->procedure_code ?? ''),
                'diagnosis_code' => (string) ($row->diagnosis_code ?? ''),
                'quantity' => (int) ($row->quantity ?? 0),
                'unit_price' => (float) ($row->price ?? 0),
                'amount' => round((int) ($row->quantity ?? 0) * (float) ($row->price ?? 0), 2),
                'regime' => (string) $row->regime,
                'special_category' => $row->special_category,
                'edited' => $wasEdited,
                'added_after_new_batch' => $isAdditional,
                'eligible' => $eligible,
                'suggested' => $suggested,
                'selection_source' => in_array($context['batch_type'], self::NEW_TYPES, true)
                    ? 'automatic'
                    : 'suggested',
                'reasons' => $reasons,
            ];
        });

        $eligible = $candidates->where('eligible', true)->values();
        $blocked = $candidates->where('eligible', false)->values();

        return [
            'automatic' => in_array($context['batch_type'], self::NEW_TYPES, true),
            'existing_batch' => $existingBatch ? [
                'id' => $existingBatch->id,
                'document_id' => $existingBatch->document()->value('id'),
                'status' => $existingBatch->status,
                'can_rebuild' => $canRebuildExistingBatch,
            ] : null,
            'base_new_batch' => $baseNewBatch ? [
                'id' => $baseNewBatch->id,
                'document_id' => $baseNewBatch->document_id,
                'batch_number' => $baseNewBatch->batch_number,
            ] : null,
            'candidates' => $eligible->all(),
            'blocked' => $blocked->all(),
            'summary' => [
                'points_count' => $eligible->count(),
                'patients_count' => $eligible->pluck('patient_id')->unique()->count(),
                'blocked_count' => $blocked->count(),
                'amount' => round((float) $eligible->sum('amount'), 2),
            ],
        ];
    }

    public function selectedRows(array $data, User $actor): Collection
    {
        $result = $this->candidates([
            'batch_type' => (string) data_get($data, 'batchType.code'),
            'insurance_company_id' => (int) data_get($data, 'insurance.id'),
            'branch_id' => (int) data_get($data, 'branch.id'),
            'period' => data_get($data, 'period', []),
        ], $actor);

        if (
            $result['existing_batch'] !== null
            && ! $result['existing_batch']['can_rebuild']
            && in_array((string) data_get($data, 'batchType.code'), self::NEW_TYPES, true)
        ) {
            throw ValidationException::withMessages([
                'batch' => ['Nová dávka pre zvoleného pracovníka, poisťovňu a obdobie už existuje.'],
                'existing_batch_id' => [(string) $result['existing_batch']['id']],
                'existing_document_id' => [(string) ($result['existing_batch']['document_id'] ?? '')],
            ]);
        }

        $eligible = collect($result['candidates']);
        $batchType = (string) data_get($data, 'batchType.code');

        if (in_array($batchType, self::NEW_TYPES, true)) {
            return $eligible;
        }

        $selectedIds = collect(data_get($data, 'pointIds', []))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($selectedIds->isEmpty()) {
            throw ValidationException::withMessages([
                'pointIds' => ['Vyberte aspoň jeden výkon.'],
            ]);
        }

        $selected = $eligible->whereIn('point_id', $selectedIds)->values();

        if ($selected->count() !== $selectedIds->count()) {
            throw ValidationException::withMessages([
                'pointIds' => ['Niektoré vybrané výkony už nie sú dostupné pre túto dávku. Obnovte výber.'],
            ]);
        }

        return $selected;
    }

    public function rawRowsForPointIds(array $data, User $actor, Collection $pointIds): Collection
    {
        $context = $this->context([
            'batch_type' => (string) data_get($data, 'batchType.code'),
            'insurance_company_id' => (int) data_get($data, 'insurance.id'),
            'branch_id' => (int) data_get($data, 'branch.id'),
            'period' => data_get($data, 'period', []),
        ], $actor);

        return $this->queryRows($context)
            ->whereIn('patient_point_id', $pointIds->map(fn ($id) => (int) $id))
            ->values();
    }

    private function context(array $data, User $actor): array
    {
        $branch = Branch::query()->findOrFail((int) $data['branch_id']);

        if (! $actor->isInBranch((int) $branch->id)) {
            abort(403, 'K vybranej pobočke nemáte prístup.');
        }

        $from = Carbon::parse($data['period'][0])->startOfDay()->toDateString();
        $to = Carbon::parse($data['period'][1])->endOfDay()->toDateString();
        $batchType = (string) $data['batch_type'];

        return [
            'batch_type' => $batchType,
            'regime' => $this->regimeFor($batchType),
            'insurance_company_id' => (int) $data['insurance_company_id'],
            'branch_id' => (int) $branch->id,
            'company_id' => (int) $branch->company_id,
            'healthcare_worker_id' => (int) $actor->id,
            'from' => $from,
            'to' => $to,
            'accounting_period' => Carbon::parse($from)->startOfMonth()->toDateString(),
        ];
    }

    private function queryRows(array $context): Collection
    {
        return DB::table('patient_points as pp')
            ->join('patients as p', 'p.id', '=', 'pp.patient_id')
            ->leftJoin('doctors as d', 'd.id', '=', 'p.doctor_id')
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
            ->leftJoin('procedure_company_prices as pcp', function ($join) use ($context) {
                $join->on('pcp.procedure_id', '=', 'pp.procedure_id')
                    ->on('pcp.insurance_company_id', '=', 'pc.insurance_company_id')
                    ->where('pcp.company_id', '=', $context['company_id']);
            })
            ->where('pp.user_id', $context['healthcare_worker_id'])
            ->where('pp.branch_id', $context['branch_id'])
            ->where('pc.insurance_company_id', $context['insurance_company_id'])
            ->where('pc.regime', $context['regime'])
            ->whereBetween('pp.date', [$context['from'], $context['to']])
            ->orderBy('pp.id')
            ->orderByDesc('pc.valid_from')
            ->select([
                'pp.id as patient_point_id',
                'pp.date',
                'pp.reference_date as request_date',
                'pp.patient_id',
                'pp.diagnosis_code',
                'pp.procedure_code',
                'pp.quantity',
                'pp.doctor_pzs',
                'pp.doctor_zpr',
                'pp.created_at',
                'pp.updated_at',
                'p.personal_number',
                'p.first_name',
                'p.last_name',
                'p.sex',
                'p.latitude',
                'p.longitude',
                'pc.id as coverage_id',
                'pc.regime',
                'pc.member_state_code',
                'pc.foreign_insured_id',
                'pc.special_category',
                'pc.entitlement_document_type',
                'pc.entitlement_document_number',
                'd.pzs as current_doctor_pzs',
                'd.zpr as current_doctor_zpr',
                'pcp.price',
            ])
            ->get()
            ->unique('patient_point_id')
            ->values();
    }

    private function correctableLines(array $context): Collection
    {
        $lines = DB::table('point_claim_lines as line')
            ->join('point_claim_batches as batch', 'batch.id', '=', 'line.batch_id')
            ->where('batch.healthcare_worker_id', $context['healthcare_worker_id'])
            ->where('batch.insurance_company_id', $context['insurance_company_id'])
            ->whereNull('batch.deleted_at')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('point_claim_lines as correction')
                    ->join('point_claim_batches as correction_batch', 'correction_batch.id', '=', 'correction.batch_id')
                    ->whereColumn('correction.previous_claim_line_id', 'line.id')
                    ->whereNull('correction_batch.deleted_at')
                    ->where('correction_batch.status', '<>', 'cancelled');
            })
            ->orderByDesc('line.id')
            ->get(['line.id', 'line.patient_point_id'])
            ->unique('patient_point_id');

        return $lines
            ->mapWithKeys(fn ($line) => [
                (int) $line->patient_point_id => [
                    'line_id' => (int) $line->id,
                ],
            ]);
    }

    private function existingNewBatch(array $context): ?PointClaimBatch
    {
        if (! in_array($context['batch_type'], self::NEW_TYPES, true)) {
            return null;
        }

        return PointClaimBatch::query()
            ->where('healthcare_worker_id', $context['healthcare_worker_id'])
            ->where('insurance_company_id', $context['insurance_company_id'])
            ->whereDate('accounting_period', $context['accounting_period'])
            ->where('batch_type', $context['batch_type'])
            ->where('status', '<>', 'cancelled')
            ->first();
    }

    private function baseNewBatch(array $context): ?PointClaimBatch
    {
        $newType = match ($context['batch_type']) {
            'A' => 'N',
            'G' => 'E',
            'K' => 'I',
            default => null,
        };

        if (! $newType) {
            return null;
        }

        return PointClaimBatch::query()
            ->where('healthcare_worker_id', $context['healthcare_worker_id'])
            ->where('insurance_company_id', $context['insurance_company_id'])
            ->whereDate('accounting_period', $context['accounting_period'])
            ->where('batch_type', $newType)
            ->where('status', '<>', 'cancelled')
            ->latest('id')
            ->first();
    }

    private function blockingReasons(object $row, string $batchType): array
    {
        $reasons = [];

        if (blank($row->first_name) || blank($row->last_name)) {
            $reasons[] = 'Pacient nemá vyplnené meno a priezvisko.';
        }
        if (blank($row->personal_number) && blank($row->foreign_insured_id)) {
            $reasons[] = 'Chýba rodné číslo alebo zahraničné identifikačné číslo poistenca.';
        }
        if (blank($row->diagnosis_code)) {
            $reasons[] = 'Chýba diagnóza.';
        }
        if (blank($row->procedure_code)) {
            $reasons[] = 'Chýba kód výkonu.';
        }
        if ((int) $row->quantity < 1) {
            $reasons[] = 'Množstvo výkonu musí byť aspoň 1.';
        }
        if ($row->price === null) {
            $reasons[] = 'Pre výkon nie je nastavená cena pre vybranú poisťovňu.';
        }
        if (in_array($batchType, ['E', 'F', 'G'], true) && blank($row->member_state_code)) {
            $reasons[] = 'Chýba štát poistenia.';
        }
        if (in_array($batchType, ['I', 'J', 'K'], true) && blank($row->special_category)) {
            $reasons[] = 'Chýba osobitná kategória poistenia.';
        }

        return $reasons;
    }

    private function regimeFor(string $batchType): string
    {
        return match (true) {
            in_array($batchType, ['N', 'O', 'A'], true) => 'domestic',
            in_array($batchType, ['E', 'F', 'G'], true) => 'eu',
            in_array($batchType, ['I', 'J', 'K'], true) => 'special',
            default => throw ValidationException::withMessages(['batch_type' => ['Neplatný charakter dávky.']]),
        };
    }
}
