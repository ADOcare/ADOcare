<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DekurzBuilderRequest;
use App\Http\Requests\InitializeDekurzDraftRequest;
use App\Http\Requests\PreviewDekurzDraftRequest;
use App\Http\Requests\UpdateDekurzDayRequest;
use App\Http\Requests\UpdateDekurzEntryRequest;
use App\Http\Requests\UpsertPatientProcedureDekurzTemplateRequest;
use App\Models\DekurzDay;
use App\Models\DekurzDraft;
use App\Models\DekurzEntry;
use App\Models\Patient;
use App\Models\Procedure;
use App\Services\DekurzBuilderService;
use App\Services\DekurzDraftInitializer;
use App\Services\DekurzEntryBatchService;
use App\Services\DekurzV2Finalizer;
use App\Services\DekurzV2PreviewService;
use App\Services\DocumentService;
use App\Services\PatientProcedureDekurzTemplateService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DekurzV2Controller extends Controller
{
    public function __construct(
        private DekurzBuilderService $builder,
        private DekurzDraftInitializer $draftInitializer,
        private DekurzEntryBatchService $entryBatch,
        private DekurzV2Finalizer $finalizer,
        private DekurzV2PreviewService $preview,
        private PatientProcedureDekurzTemplateService $templates,
    ) {}

    public function builder(DekurzBuilderRequest $request, Patient $patient)
    {
        $this->authorize('view', $patient);

        return $this->success(
            $this->builder->build($patient, $request->validated('month')),
            'Podklady pre Dekurz boli načítané.',
        );
    }

    public function initializeDraft(InitializeDekurzDraftRequest $request, Patient $patient)
    {
        $this->authorize('update', $patient);
        $month = $request->validated('month');
        $this->draftInitializer->initialize($patient, $month, $request->user());

        return $this->success(
            $this->builder->build($patient, $month),
            'Koncept Dekurzu bol pripravený.',
        );
    }

    public function previewDraft(PreviewDekurzDraftRequest $request, DocumentService $documents)
    {
        $patient = Patient::query()->findOrFail($request->integer('patient_id'));
        $this->authorize('view', $patient);
        $payload = $this->preview->build(
            $patient,
            $request->validated('month'),
            $request->user(),
            $request->validated('dekurz_number'),
            $request->validated('days'),
        );

        return response()->view('pdf.dekurz', [
            'dekurzData' => $payload,
            'signatureDataUri' => $documents->getUserSignatureDataUri((int) $request->user()->id),
        ])->withHeaders([
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    public function upsertTemplate(
        UpsertPatientProcedureDekurzTemplateRequest $request,
        Patient $patient,
        Procedure $procedure,
    ) {
        $this->authorize('update', $patient);

        return $this->success(
            $this->templates->upsert($patient, $procedure, $request->validated('text'), $request->user()),
            'Text výkonu bol uložený.',
        );
    }

    public function deleteTemplate(Request $request, Patient $patient, Procedure $procedure)
    {
        $this->authorize('update', $patient);
        $this->templates->delete($patient, $procedure);

        return response()->noContent();
    }

    public function updateDay(
        UpdateDekurzDayRequest $request,
        Patient $patient,
        DekurzDraft $draft,
        DekurzDay $day,
    ) {
        $this->authorize('update', $patient);
        $this->assertEditableDraftRelations($patient, $draft, $day);
        $day->update($request->validated());
        $draft->update(['updated_by' => $request->user()->id]);

        return $this->success($day->fresh(), 'Poznámka dňa bola uložená.');
    }

    public function updateEntry(
        UpdateDekurzEntryRequest $request,
        Patient $patient,
        DekurzDraft $draft,
        DekurzEntry $entry,
    ) {
        $this->authorize('update', $patient);
        $entry->loadMissing('day');
        $this->assertEditableDraftRelations($patient, $draft, $entry->day);
        $entry->update([
            'text' => $request->validated('text'),
            'source' => 'manual',
            'edited_at' => now(),
        ]);
        $draft->update(['updated_by' => $request->user()->id]);

        return $this->success($entry->fresh(), 'Denný text bol uložený.');
    }

    public function applyEntryToUnedited(
        Request $request,
        Patient $patient,
        DekurzDraft $draft,
        DekurzEntry $entry,
    ) {
        $this->authorize('update', $patient);
        $entry->loadMissing('day');
        $this->assertEditableDraftRelations($patient, $draft, $entry->day);

        return $this->success(
            $this->entryBatch->applyToUnedited($draft, $entry),
            'Text bol použitý pre neupravené dni.',
        );
    }

    public function finalize(Request $request, Patient $patient, DekurzDraft $draft)
    {
        $this->authorize('update', $patient);
        $this->assertDraftPatientRelation($patient, $draft);
        $document = $this->finalizer->finalize($patient, $draft, $request->user());

        return $this->success([
            'document_id' => $document->id,
            'next_dekurz_number' => $document->next_dekurz_number,
            'preview_url' => "/v1/dekurz/{$document->id}/preview",
            'download_url' => "/v1/dekurz/{$document->id}/download",
        ], 'Dekurz bol úspešne finalizovaný.', 201);
    }

    private function assertDraftPatientRelation(Patient $patient, DekurzDraft $draft): void
    {
        if ((int) $draft->patient_id !== (int) $patient->id) {
            throw ValidationException::withMessages([
                'draft' => 'Koncept Dekurzu patrí inému pacientovi.',
            ]);
        }
    }

    private function assertEditableDraftRelations(Patient $patient, DekurzDraft $draft, DekurzDay $day): void
    {
        $this->assertDraftPatientRelation($patient, $draft);

        if ((int) $day->dekurz_draft_id !== (int) $draft->id) {
            throw ValidationException::withMessages([
                'draft' => 'Koncept, deň alebo pacient spolu nesúvisia.',
            ]);
        }

        if ($draft->status !== DekurzDraft::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'draft' => 'Dokončený Dekurz nie je možné upravovať.',
            ]);
        }
    }
}
