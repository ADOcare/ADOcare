<?php

namespace App\Services;

use App\Models\DekurzDraft;
use App\Models\Document;
use App\Models\Patient;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class DekurzV2Finalizer
{
    public function __construct(
        private DekurzFinalizationValidator $validator,
        private DekurzV2SnapshotBuilder $snapshots,
        private DocumentService $documents,
    ) {}

    public function finalize(Patient $patient, DekurzDraft $draft, User $actor, ?int $previewPageCount = null): Document
    {
        $jsonPath = null;
        $pdfPath = null;

        try {
            return DB::transaction(function () use ($patient, $draft, $actor, $previewPageCount, &$jsonPath, &$pdfPath): Document {
                $lockedPatient = Patient::query()->lockForUpdate()->findOrFail($patient->id);
                $lockedDraft = DekurzDraft::query()->lockForUpdate()->findOrFail($draft->id);
                $previousDocumentId = $lockedDraft->final_document_id;

                if (! $previousDocumentId) {
                    $lockedDraft->dekurz_number = max(1, (int) $lockedPatient->dekurz_number);
                    $lockedDraft->save();
                }

                $points = $this->validator->validate($lockedPatient, $lockedDraft, $actor);
                $period = Carbon::parse($lockedDraft->period)->format('Y-m');
                $jsonPath = 'dekurz/'.Str::uuid().'.json';
                $document = Document::create([
                    'patient_id' => $lockedPatient->id,
                    'user_id' => $actor->id,
                    'type' => 'dekurz',
                    'mime_type' => 'application/json',
                    'name' => 'dekurz_'.now()->format('d.m.Y'),
                    'path' => $jsonPath,
                    'period' => $period,
                    'branch_id' => $lockedDraft->branch_id,
                    'company_id' => $lockedPatient->branch?->company_id,
                    'insurance_company_id' => $lockedPatient->insurance_company_id,
                ]);
                $snapshot = $this->snapshots->build($lockedPatient, $lockedDraft, $actor, $document, $points);
                $encoded = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

                if (! Storage::disk('local')->put($jsonPath, $encoded)) {
                    throw new RuntimeException('Nepodarilo sa uložiť snapshot Dekurzu.');
                }

                $pageCount = null;
                $pdfPath = $this->documents->getTravelDocumentPdfPath($document, $pageCount);
                if (! $pdfPath) {
                    throw new RuntimeException('Nepodarilo sa vygenerovať PDF Dekurzu.');
                }

                $lockedDraft->update([
                    'status' => DekurzDraft::STATUS_COMPLETED,
                    'final_document_id' => $document->id,
                    'completed_at' => now(),
                    'updated_by' => $actor->id,
                ]);

                if ($previousDocumentId && (int) $previousDocumentId !== (int) $document->id) {
                    Document::query()
                        ->whereKey($previousDocumentId)
                        ->where('patient_id', $lockedPatient->id)
                        ->where('type', 'dekurz')
                        ->delete();
                }

                $nextNumber = max(1, (int) $lockedPatient->dekurz_number);
                if (! $previousDocumentId) {
                    $numberedPageCount = $previewPageCount ?? $pageCount;
                    $nextNumber = (int) $lockedDraft->dekurz_number + max(1, (int) $numberedPageCount);
                    $lockedPatient->update(['dekurz_number' => (string) $nextNumber]);
                }
                $document->next_dekurz_number = $nextNumber;

                return $document;
            }, 3);
        } catch (Throwable $exception) {
            if ($pdfPath && Storage::disk('local')->exists($pdfPath)) {
                Storage::disk('local')->delete($pdfPath);
            }
            if ($jsonPath && Storage::disk('local')->exists($jsonPath)) {
                Storage::disk('local')->delete($jsonPath);
            }

            throw $exception;
        }
    }
}
