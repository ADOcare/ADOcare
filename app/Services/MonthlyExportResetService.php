<?php

namespace App\Services;

use App\Models\Document;
use App\Models\PointClaimBatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MonthlyExportResetService
{
    private const DOCUMENT_TYPES = [
        'points_batch',
        'kilometers_batch',
        'dzc',
        'cp',
    ];

    public function reset(int $userId, int $branchId, string $period): void
    {
        $documents = Document::withTrashed()
            ->where('user_id', $userId)
            ->where('branch_id', $branchId)
            ->where('period', $period)
            ->whereIn('type', self::DOCUMENT_TYPES)
            ->get();

        $documentIds = $documents->modelKeys();

        DB::transaction(function () use ($branchId, $documentIds, $period, $userId): void {
            PointClaimBatch::withTrashed()
                ->where('healthcare_worker_id', $userId)
                ->where('branch_id', $branchId)
                ->whereDate('accounting_period', $period . '-01')
                ->get()
                ->each
                ->forceDelete();

            $transportBatchIds = DB::table('transport_claim_batches')
                ->where('user_id', $userId)
                ->where('branch_id', $branchId)
                ->where('period', $period)
                ->pluck('id');

            DB::table('transport_claim_lines')
                ->whereIn('batch_id', $transportBatchIds)
                ->delete();
            DB::table('transport_claim_batches')
                ->whereIn('id', $transportBatchIds)
                ->delete();
            DB::table('transport_odometer_readings')
                ->whereIn('document_id', $documentIds)
                ->delete();

            Document::withTrashed()
                ->whereIn('id', $documentIds)
                ->forceDelete();
        });

        $this->deleteAssets($documents);
    }

    /** @param Collection<int, Document> $documents */
    private function deleteAssets(Collection $documents): void
    {
        $disk = Storage::disk('local');

        foreach ($documents as $document) {
            if ($document->path && $disk->exists($document->path)) {
                $disk->delete($document->path);
            }

            $pdfPath = sprintf('documents/pdf/%s/%d.pdf', $document->type, $document->id);
            if ($disk->exists($pdfPath)) {
                $disk->delete($pdfPath);
            }
        }
    }
}
