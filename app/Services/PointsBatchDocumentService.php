<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Document;
use App\Models\PointClaimBatch;
use App\Models\PointClaimLine;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;


class PointsBatchDocumentService
{
    public function __construct(
        private PointClaimSelectionService $selectionService,
        private PointsBatchNumberService $batchNumberService,
    ) {
    }

    public function getPointsBatchPayload(Document $document): ?array
    {
        if (! $document->path || ! Storage::disk('local')->exists($document->path)) {
            return null;
        }

        return json_decode(Storage::disk('local')->get($document->path), true);
    }


    public function createPointsBatch(array $data, $actor): array
    {
        $insuranceId = (int) data_get($data, 'insurance.id');
        $branchId  = (int) data_get($data, 'branch.id');
        $branch = Branch::query()->findOrFail($branchId);
        abort_unless($actor && $actor->isInBranch($branchId), 403);

        $companyId = (int) $branch->company_id;
        $insuranceId = (int) data_get($data, 'insurance.id');

        $periodFromRaw = (string) data_get($data, 'period.0');
        $periodToRaw   = (string) data_get($data, 'period.1');

        $subtype = (string) data_get($data, 'batchType.code', 'N');
        $isNewBatch = in_array($subtype, ['N', 'E', 'I'], true);
        $batchNumber = $this->batchNumberService->make($actor, $insuranceId, $periodFromRaw);

        $data['user'] = ['id' => (int) $actor->id];

        $tz = 'Europe/Bratislava';
        $to = Carbon::parse($periodToRaw)->setTimezone($tz);

        $periodKey = $to->format('Y-m');

        return DB::transaction(function () use (
            $data, $actor, $branchId, $companyId, $periodFromRaw, $periodToRaw, $periodKey, $subtype, $insuranceId,
            $isNewBatch, $batchNumber,
        ) {
            $type = 'points_batch';

            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [
                implode(':', [
                    'points-new-batch',
                    $actor->id,
                    $insuranceId,
                    $periodKey,
                    $subtype,
                ]),
            ]);

            $selectedRows = $this->selectionService->selectedRows($data, $actor);

            if ($selectedRows->isEmpty()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'pointIds' => ['Pre zvolené obdobie sa nenašli žiadne výkony.'],
                ]);
            }

            $selectedPointIds = $selectedRows
                ->pluck('point_id')
                ->map(fn ($id) => (int) $id)
                ->values();

            $rawRows = $this->selectionService
                ->rawRowsForPointIds($data, $actor, $selectedPointIds)
                ->keyBy(fn ($row) => (int) $row->patient_point_id);

            if ($isNewBatch) {
                $oldBatch = PointClaimBatch::query()
                    ->where('healthcare_worker_id', $actor->id)
                    ->where('insurance_company_id', $insuranceId)
                    ->whereDate('accounting_period', Carbon::parse($periodFromRaw)->startOfMonth()->toDateString())
                    ->where('batch_type', $subtype)
                    ->where('status', '<>', 'cancelled')
                    ->lockForUpdate()
                    ->first();

                $oldDocuments = Document::query()
                    ->where('type', $type)
                    ->where('subtype', $subtype)
                    ->where('user_id', $actor->id)
                    ->where('period', $periodKey)
                    ->where('insurance_company_id', $insuranceId)
                    ->lockForUpdate()
                    ->get();

                $oldPaths = $oldDocuments
                    ->pluck('path')
                    ->filter()
                    ->values()
                    ->all();

                $oldBatch?->forceDelete();
                $oldDocuments->each->forceDelete();

                DB::afterCommit(function () use ($oldPaths) {
                    foreach ($oldPaths as $oldPath) {
                        if (Storage::disk('local')->exists($oldPath)) {
                            Storage::disk('local')->delete($oldPath);
                        }
                    }
                });
            }

            $newPath = 'points_batches/' . Str::uuid() . '_' . $batchNumber . '.json';

            $document = Document::create([
                'patient_id' => null,
                'branch_id' => $branchId,
                'company_id' => $companyId ?: null,
                'user_id' => $actor->id,
                'insurance_company_id' => $insuranceId,
                'type' => $type,
                'subtype' => $subtype,
                'mime_type' => 'application/json',
                'name' => 'points_' . $subtype . '_davka_' . $batchNumber . '_' . now()->format('d.m.Y'),
                'path' => $newPath,
                'period' => $periodKey,
            ]);

            $meta = (array) data_get($data, 'meta', []);
            $meta['fileName'] = 'davka.' . $batchNumber . '.txt';
            $meta['amount'] = round((float) $selectedRows->sum('amount'), 2);

            $payload = [
                'document_id' => $document->id,
                'batchNumber' => $batchNumber,
                'batchType'   => ['code' => $subtype],
                'insurance'   => ['id' => (int) data_get($data, 'insurance.id')],
                'period'      => [$periodFromRaw, $periodToRaw],
                'user'        => ['id' => $actor->id],
                'branch'      => ['id' => $branchId],
                'company'     => ['id' => $companyId ?: null],
                'pointIds'    => $selectedPointIds->all(),
                'meta' => $meta,
                'saved_at' => now(),
            ];

            $batchAttributes = [
                'document_id' => $document->id,
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'healthcare_worker_id' => $actor->id,
                'created_by' => $actor->id,
                'insurance_company_id' => $insuranceId,
                'batch_type' => $subtype,
                'accounting_period' => Carbon::parse($periodFromRaw)->startOfMonth()->toDateString(),
                'batch_number' => $batchNumber,
                'invoice_number' => data_get($data, 'invoiceNumber'),
                'status' => 'finalized',
                'total_amount' => $selectedRows->sum('amount'),
                'finalized_at' => now(),
                'exported_at' => null,
            ];

            $claimBatch = PointClaimBatch::create($batchAttributes);

            foreach ($selectedRows as $selectedRow) {
                $pointId = (int) $selectedRow['point_id'];
                $rawRow = $rawRows->get($pointId);
                $snapshot = $rawRow ? [
                    'patient_point_id' => (int) $rawRow->patient_point_id,
                    'date' => (string) $rawRow->date,
                    'request_date' => $rawRow->request_date ? (string) $rawRow->request_date : null,
                    'patient_id' => (int) $rawRow->patient_id,
                    'personal_number' => $rawRow->personal_number,
                    'last_name' => $rawRow->last_name,
                    'first_name' => $rawRow->first_name,
                    'sex' => $rawRow->sex,
                    'latitude' => $rawRow->latitude,
                    'longitude' => $rawRow->longitude,
                    'country_code' => $rawRow->member_state_code,
                    'foreign_insured_id' => $rawRow->foreign_insured_id,
                    'special_category' => $rawRow->special_category,
                    'entitlement_document_type' => $rawRow->entitlement_document_type,
                    'entitlement_document_number' => $rawRow->entitlement_document_number,
                    'diagnosis_code' => $rawRow->diagnosis_code,
                    'procedure_code' => $rawRow->procedure_code,
                    'quantity' => (int) $rawRow->quantity,
                    'doctor_pzs' => $rawRow->doctor_pzs ?: $rawRow->current_doctor_pzs,
                    'doctor_zpr' => $rawRow->doctor_zpr ?: $rawRow->current_doctor_zpr,
                    'price' => (float) $rawRow->price,
                    'sender_type' => 'O',
                    'patient_type' => null,
                ] : $selectedRow;

                PointClaimLine::create([
                    'batch_id' => $claimBatch->id,
                    'patient_point_id' => $pointId,
                    'coverage_id' => $selectedRow['coverage_id'] ?: null,
                    'previous_claim_line_id' => $selectedRow['previous_claim_line_id'] ?: null,
                    'selection_source' => $isNewBatch
                        ? 'automatic'
                        : ($selectedRow['suggested'] ? 'suggested' : 'manual'),
                    'status' => 'pending',
                    'quantity' => $selectedRow['quantity'],
                    'unit_price' => $selectedRow['unit_price'],
                    'amount' => $selectedRow['amount'],
                    'snapshot' => $snapshot,
                ]);
            }

            $payload['claimBatchId'] = $claimBatch->id;

            Storage::disk('local')->put(
                $document->path,
                json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );

            return [$document, $payload];
        });
    }
}
