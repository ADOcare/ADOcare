<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DekurzBuilderRequest;
use App\Http\Requests\InitializeDekurzDraftRequest;
use App\Http\Requests\PreviewDekurzDraftRequest;
use App\Http\Requests\PropagateDekurzEntryRequest;
use App\Http\Requests\UpdateDekurzDayRequest;
use App\Http\Requests\UpdateDekurzEntryRequest;
use App\Http\Requests\UpsertPatientProcedureDekurzTemplateRequest;
use App\Models\DekurzDay;
use App\Models\DekurzDraft;
use App\Models\DekurzEntry;
use App\Models\Patient;
use App\Models\PatientDekurzCustomTrack;
use App\Models\PatientDekurzCustomTrackPeriod;
use App\Models\Procedure;
use App\Services\DekurzBuilderService;
use App\Services\DekurzDraftInitializer;
use App\Services\DekurzEntryBatchService;
use App\Services\DekurzV2Finalizer;
use App\Services\DekurzV2PreviewService;
use App\Services\DocumentService;
use App\Services\PatientProcedureDekurzTemplateService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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

        return $this->success($this->builder->build($patient, $request->validated('month')), 'Podklady pre Dekurz boli načítané.');
    }

    public function initializeDraft(InitializeDekurzDraftRequest $request, Patient $patient)
    {
        $this->authorize('update', $patient);
        $month = $request->validated('month');

        $this->draftInitializer->initialize(
            $patient,
            $month,
            $request->user(),
        );

        return $this->success(
            $this->builder->build($patient, $month),
            'Dekurz bol pripravený na úpravu.',
        );
    }

    public function previewDraft(PreviewDekurzDraftRequest $request, DocumentService $documents)
    {
        $patient = Patient::query()->findOrFail($request->integer('patient_id'));
        $this->authorize('view', $patient);
        $payload = $this->preview->build($patient, $request->validated('month'), $request->user(), $request->validated('dekurz_number'), $request->validated('days'));

        return response()->view('pdf.dekurz', [
            'dekurzData' => $payload,
            'signatureDataUri' => $documents->getUserSignatureDataUri((int) $request->user()->id),
        ])->withHeaders(['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store, no-cache, must-revalidate, private']);
    }

    public function upsertTemplate(UpsertPatientProcedureDekurzTemplateRequest $request, Patient $patient, Procedure $procedure)
    {
        $this->authorize('update', $patient);

        return $this->success($this->templates->upsert($patient, $procedure, $request->validated('text'), $request->user()), 'Text výkonu bol uložený.');
    }

    public function deleteTemplate(Request $request, Patient $patient, Procedure $procedure)
    {
        $this->authorize('update', $patient);
        $this->templates->delete($patient, $procedure);

        return response()->noContent();
    }

    public function storeCustomTrack(Request $request, Patient $patient)
    {
        $this->authorize('update', $patient);
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'name' => ['required', 'string', 'max:120'],
            'current_text' => ['nullable', 'string', 'max:20000'],
            'distribution' => ['required', Rule::in(['visit_days', 'selected_days'])],
            'selected_dates' => ['nullable', 'array'],
            'selected_dates.*' => ['date_format:Y-m-d'],
        ]);
        $track = PatientDekurzCustomTrack::query()->firstOrCreate(
            ['patient_id' => $patient->id, 'name' => trim($data['name'])],
            ['current_text' => $data['current_text'] ?? null],
        );
        if (array_key_exists('current_text', $data)) {
            $track->update(['current_text' => $data['current_text']]);
        }
        $period = PatientDekurzCustomTrackPeriod::query()->firstOrNew([
            'custom_track_id' => $track->id,
            'period' => $data['month'].'-01',
        ]);

        $selectedDates = collect($period->selected_dates ?? [])
            ->merge($data['selected_dates'] ?? [])
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        $period->fill([
            'distribution' => $data['distribution'],
            'selected_dates' => $selectedDates,
        ]);
        $period->save();

        return $this->success($track->fresh(), 'Vlastný záznam bol pridaný.', 201);
    }

    public function updateCustomTrack(Request $request, Patient $patient, PatientDekurzCustomTrack $customTrack)
    {
        $this->authorize('update', $patient);
        $this->assertCustomTrackPatient($patient, $customTrack);
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'name' => ['required', 'string', 'max:120'],
            'current_text' => ['nullable', 'string', 'max:20000'],
            'distribution' => ['required', Rule::in(['visit_days', 'selected_days'])],
            'selected_dates' => ['nullable', 'array'],
            'selected_dates.*' => ['date_format:Y-m-d'],
        ]);
        $customTrack->update(['name' => trim($data['name']), 'current_text' => $data['current_text'] ?? null]);
        PatientDekurzCustomTrackPeriod::query()->updateOrCreate(
            ['custom_track_id' => $customTrack->id, 'period' => $data['month'].'-01'],
            ['distribution' => $data['distribution'], 'selected_dates' => $data['selected_dates'] ?? []],
        );

        return $this->success($customTrack->fresh(), 'Vlastný záznam bol uložený.');
    }

    public function deleteCustomTrack(Request $request, Patient $patient, PatientDekurzCustomTrack $customTrack)
    {
        $this->authorize('update', $patient);
        $this->assertCustomTrackPatient($patient, $customTrack);
        $month = $request->validate(['month' => ['required', 'date_format:Y-m']])['month'];
        PatientDekurzCustomTrackPeriod::query()->where('custom_track_id', $customTrack->id)->whereDate('period', $month.'-01')->delete();

        return response()->noContent();
    }

    public function updateDay(UpdateDekurzDayRequest $request, Patient $patient, DekurzDraft $draft, DekurzDay $day)
    {
        $this->authorize('update', $patient);
        $this->assertEditableDraftRelations($patient, $draft, $day);
        $day->update($request->validated());
        $draft->update(['updated_by' => $request->user()->id]);

        return $this->success($day->fresh(), 'Poznámka dňa bola uložená.');
    }

    public function updateEntry(UpdateDekurzEntryRequest $request, Patient $patient, DekurzDraft $draft, DekurzEntry $entry)
    {
        $this->authorize('update', $patient);
        $entry->loadMissing('day');
        $this->assertEditableDraftRelations($patient, $draft, $entry->day);
        $text = $request->validated('text');
        $changes = ['text' => $text];

        // Preserve provenance when the nurse only focuses/blurs the field.
        // A saved patient template must remain distinguishable from the generic
        // procedure default until the actual text is changed.
        if (trim((string) $entry->text) !== trim((string) $text)) {
            $changes['source'] = 'manual';
            $changes['edited_at'] = now();
            $changes['source_entry_id'] = null;
        }

        $entry->update($changes);
        $draft->update(['updated_by' => $request->user()->id]);

        return $this->success($entry->fresh(['procedure', 'customTrack']), 'Denný text bol uložený.');
    }

    public function excludeEntry(Request $request, Patient $patient, DekurzDraft $draft, DekurzEntry $entry)
    {
        $this->authorize('update', $patient);
        $entry->loadMissing('day');
        $this->assertEditableDraftRelations($patient, $draft, $entry->day);

        if (! $entry->isCustomTrack()) {
            throw ValidationException::withMessages([
                'entry' => 'Odstrániť je možné iba vlastné sekcie. Text patriaci k vykázanému výkonu musí zostať v Dekurze.',
            ]);
        }

        $entry->update([
            'is_excluded' => true,
        ]);

        $draft->update([
            'updated_by' => $request->user()->id,
        ]);

        return response()->noContent();
    }

    public function propagateEntry(PropagateDekurzEntryRequest $request, Patient $patient, DekurzDraft $draft, DekurzEntry $entry)
    {
        $this->authorize('update', $patient);
        $entry->loadMissing('day');
        $this->assertEditableDraftRelations($patient, $draft, $entry->day);

        return $this->success($this->entryBatch->propagate(
            $draft,
            $entry,
            $request->validated('text'),
            $request->validated('mode'),
            $request->validated('target_entry_ids', []),
            $request->validated('target_dates', []),
            (bool) $request->validated('save_as_patient_template', false),
            $request->user(),
        ), 'Text bol použitý na vybrané dni.');
    }

    public function finalize(Request $request, Patient $patient, DekurzDraft $draft)
    {
        $this->authorize('update', $patient);
        $this->assertDraftPatientRelation($patient, $draft);
        $validated = $request->validate([
            'page_count' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);
        $document = $this->finalizer->finalize(
            $patient,
            $draft,
            $request->user(),
            isset($validated['page_count']) ? (int) $validated['page_count'] : null,
        );

        return $this->success([
            'document_id' => $document->id,
            'next_dekurz_number' => $document->next_dekurz_number,
            'preview_url' => "/v1/dekurz/{$document->id}/preview",
            'download_url' => "/v1/dekurz/{$document->id}/download",
        ], 'Dekurz bol úspešne finalizovaný.', 201);
    }

    private function assertCustomTrackPatient(Patient $patient, PatientDekurzCustomTrack $track): void
    {
        if ((int) $track->patient_id !== (int) $patient->id) {
            throw ValidationException::withMessages(['custom_track' => 'Vlastný záznam patrí inému pacientovi.']);
        }
    }

    private function assertDraftPatientRelation(Patient $patient, DekurzDraft $draft): void
    {
        if ((int) $draft->patient_id !== (int) $patient->id) {
            throw ValidationException::withMessages(['draft' => 'Koncept Dekurzu patrí inému pacientovi.']);
        }
    }

    private function assertEditableDraftRelations(Patient $patient, DekurzDraft $draft, DekurzDay $day): void
    {
        $this->assertDraftPatientRelation($patient, $draft);
        if ((int) $day->dekurz_draft_id !== (int) $draft->id) {
            throw ValidationException::withMessages(['draft' => 'Koncept, deň alebo pacient spolu nesúvisia.']);
        }
        if ($draft->status !== DekurzDraft::STATUS_DRAFT) {
            throw ValidationException::withMessages(['draft' => 'Dokončený Dekurz nie je možné upravovať.']);
        }
    }
}
