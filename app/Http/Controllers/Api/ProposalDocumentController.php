<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PreviewProposalRequest;
use App\Http\Requests\StoreProposalRequest;
use App\Http\Requests\ValidateProposalRulesRequest;
use App\Models\Document;
use App\Models\Patient;
use App\Services\AdosProposalValidationService;
use App\Services\DocumentService;
use App\Services\ProposalDocumentService;
use App\Services\ProposalOcrPrefillService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class ProposalDocumentController extends Controller
{
    public function __construct(
        private ProposalDocumentService $service,
        private ProposalOcrPrefillService $ocrPrefillService,
        private AdosProposalValidationService $proposalValidationService,
    ) {}

    /**
     * Store a new proposal document.
     *
     * @group Documents
     *
     * @bodyParam patient_id integer required Patient ID. Example: 1
     * @bodyParam medical_diagnosis_ids integer[] nullable Array of medical diagnosis IDs. Example: [1, 2]
     * @bodyParam nurse_diagnosis_ids integer[] nullable Array of nurse diagnosis IDs. Example: [3, 4]
     * @bodyParam date date required Document date. Example: 2024-01-01
     * @bodyParam epicrisis_description string required
     * @bodyParam care_plan string required
     * @bodyParam expected_duration string required
     * @bodyParam procedures array nullable Array of procedures
     *
     * @response 201 {"document_id":123, "proposal": {"document_id":123}}
     */
    public function store(StoreProposalRequest $request)
    {
        $data = $request->validated();
        $validation = $this->proposalValidationService->validate(
            Patient::query()->findOrFail($data['patient_id']),
            $data,
        );

        if ($validation['errors'] !== []) {
            return response()->json([
                'message' => 'Návrh obsahuje chyby podľa pravidiel ADOS.',
                'data' => $validation,
            ], 422);
        }

        [$document, $payload] = $this->service->createProposal($data, $request->user());

        return $this->success([
            'document_id' => $document->id,
            'proposal' => $payload,
        ], 'Návrh ošetrovateľskej starostlivosti bol úspešne vytvorený', 201);
    }

    public function validateRules(ValidateProposalRulesRequest $request)
    {
        $data = $request->validated();
        $result = $this->proposalValidationService->validate(
            Patient::query()->findOrFail($data['patient_id']),
            $data,
        );

        return $this->success($result, 'Kontrola pravidiel ADOS bola dokončená.');
    }

    public function previewDraft(PreviewProposalRequest $request)
    {
        $payload = $this->service->previewProposal($request->validated(), $request->user());
        $documentService = app(DocumentService::class);

        return response()->view('pdf.proposal', [
            'proposalData' => $payload,
            'stampDataUri' => $documentService->getCompanyStampDataUri((int) ($payload['company_id'] ?? 0)),
            'signatureDataUri' => $documentService->getUserSignatureDataUri((int) ($payload['representative_id'] ?? 0)),
        ])->header('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Show proposal payload for a document (route-model binding).
     *
     * @group Documents
     *
     * @urlParam document int required Document ID. Example: 123
     *
     * @response 200 {"document": {"id":123}, "proposal_data": {...}}
     */
    public function show(Document $document)
    {
        $document->loadMissing('patient');
        $payload = $this->service->getProposalPayload($document);
        if (! $payload) {
            return $this->error('Dáta návrhu sa nenašli', 404);
        }

        return $this->success(['document' => $document, 'proposal_data' => $payload]);
    }

    /**
     * Return latest proposal for a patient (route-model binding).
     *
     * @group Documents
     *
     * @urlParam patient int required Patient ID. Example: 1
     *
     * @response 200 {"document_id":123, "proposal_data": {...}}
     */
    public function latestByPatient(Patient $patient)
    {
        $document = Document::where('patient_id', $patient->id)
            ->where('type', 'proposal')
            ->orderBy('created_at', 'desc')
            ->first();

        if (! $document) {
            return $this->success([
                'document_id' => null,
                'proposal_data' => null,
            ], 'Pacient nemá uložený návrh ošetrovateľskej starostlivosti.');
        }

        $payload = $this->service->getProposalPayload($document);
        if (! $payload) {
            return $this->success([
                'document_id' => $document->id,
                'proposal_data' => null,
            ], 'Dáta posledného návrhu ošetrovateľskej starostlivosti sa nenašli.');
        }

        return $this->success(['document_id' => $document->id, 'proposal_data' => $payload]);
    }

    /**
     * Return availability of OCR prefill from the latest scan for patient.
     *
     * @group Documents
     *
     * @urlParam patient int required Patient ID. Example: 1
     */
    public function ocrPrefillAvailability(Patient $patient)
    {
        $availability = $this->ocrPrefillService->getLatestScanAvailability($patient);

        return $this->success($availability, 'Dostupnosť OCR prefillu bola načítaná.');
    }

    /**
     * Build proposal prefill from latest patient scan OCR via Vertex AI.
     *
     * @group Documents
     *
     * @urlParam patient int required Patient ID. Example: 1
     */
    public function prefillFromLatestScan(Patient $patient)
    {
        try {
            $data = $this->ocrPrefillService->buildPrefillFromLatestScan($patient);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            report($e);

            return $this->error('Nepodarilo sa spracovať OCR dokument pomocou AI.', 500);
        }

        return $this->success($data, 'Prefill bol úspešne vygenerovaný z OCR dokumentu.');
    }

    /**
     * Preview proposal document as HTML via Blade template.
     *
     * @group Documents
     */
    public function preview(Document $document)
    {
        $document->loadMissing('user');

        $payload = $this->service->getProposalPayload($document);
        if (! $payload) {
            return $this->error('Dáta návrhu sa nenašli', 404);
        }

        $stampDataUri = app(DocumentService::class)->getCompanyStampDataUri((int) ($payload['company_id'] ?? 0));
        $signatureDataUri = app(DocumentService::class)->getUserSignatureDataUri((int) ($payload['representative_id'] ?? 0));

        return response()->view('pdf.proposal', [
            'proposalData' => $payload,
            'stampDataUri' => $stampDataUri,
            'signatureDataUri' => $signatureDataUri,
        ])->header('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Download proposal PDF generated from Blade template.
     *
     * @group Documents
     */
    public function download(Document $document)
    {
        $document->loadMissing('user');

        $pdfPath = app(DocumentService::class)->getTravelDocumentPdfPath($document);
        if (! $pdfPath || ! Storage::disk('local')->exists($pdfPath)) {
            return $this->error('PDF návrhu sa nenašlo', 500);
        }

        $downloadName = pathinfo($document->name, PATHINFO_FILENAME).'.pdf';

        return response()->download(Storage::disk('local')->path($pdfPath), $downloadName, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * Get a signed public preview URL for proposal document.
     *
     * @group Documents
     */
    public function previewUrl(Document $document)
    {
        $this->authorize('view', $document);

        $url = URL::temporarySignedRoute(
            'documents.public',
            now()->addMinutes(15),
            ['document' => $document->id, 'format' => 'html']
        );

        return $this->success(['preview_url' => $url]);
    }
}
