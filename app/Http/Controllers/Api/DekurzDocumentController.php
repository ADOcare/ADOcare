<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Patient;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use App\Models\PatientPoint;
use App\Http\Requests\StoreDekurzRequest;
use App\Services\DekurzAiPrefillService;
use App\Services\DekurzDocumentService;
use App\Services\DocumentService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;


class DekurzDocumentController extends Controller
{
    public function __construct(
        private DekurzAiPrefillService $aiPrefillService,
        private DocumentService $documentService,
        private DekurzDocumentService $dekurzDocumentService,
    ) {
    }

    /**
     * Store a new dekurz document.
     *
     * @group Documents
     * @bodyParam patient_id int required Patient ID. Example: 1
     * @bodyParam month date required Month date. Example: 2026-04-01
     * @response 201 {"data":{"document_id":1,"next_dekurz_number":2},"message":"Dekurz bol úspešne vytvorený"}
     */
    public function store(StoreDekurzRequest $request)
    {
        $document = $this->dekurzDocumentService->create($request->validated(), Auth::user());

        return $this->success([
            'document_id' => $document->id,
            'next_dekurz_number' => $document->next_dekurz_number,
        ], 'Dekurz bol úspešne vytvorený', 201);
    }

    /**
     * Show dekurz document payload.
     *
     * @group Documents
     * @urlParam document int required Document ID. Example: 1
     */
    public function show(Document $document)
    {
        $document->loadMissing(['user', 'patient']);

        $dekurzFile = $this->dekurzDocumentService->findDekurzFileForDocument($document);
        if (!$dekurzFile) {
            return $this->error('Dáta dekurzu sa nenašli', 404);
        }

        return $this->success([
            'document' => $document,
            'dekurz_data' => $dekurzFile,
        ]);
    }

    /**
     * Get the last dekurz data for a patient.
     *
     * @group Documents
     * @bodyParam patient_id int required Patient ID. Example: 1
     */
    public function last(Request $request)
    {
        $data = $request->validate([
            'patient_id' => 'required|integer|exists:patients,id',
        ]);
        $this->authorize('view', Patient::findOrFail((int) $data['patient_id']));

        $doc = Document::query()
            ->where('type', 'dekurz')
            ->where('patient_id', (int) $data['patient_id'])
            ->orderByDesc('id')
            ->first();
        if ($doc) {
            $dekurz = $this->dekurzDocumentService->findDekurzFileForDocument($doc);
        } else {
            // No DB document row — try to find the latest dekurz JSON for this patient in storage
            $dekurz = $this->dekurzDocumentService->findLatestDekurzForPatient((int) $data['patient_id']);
        }

        if (!$dekurz) {
            return $this->success(null);
        }

        return $this->success([
            'document_id' => $doc->id ?? ($dekurz['document_id'] ?? null),
            'sections' => $dekurz['narrative_sections'] ?? $dekurz['sections'] ?? [],
        ]);
    }

    /**
     * Get available dates for a patient's dekurz.
     *
     * @group Documents
     * @bodyParam patient_id int required Patient ID. Example: 1
     * @bodyParam month date required Month date. Example: 2026-04-01
     */
    public function availableDates(Request $request)
    {
        $data = $request->validate([
            'patient_id' => 'required|integer|exists:patients,id',
            'month' => 'required|date',
        ]);

        $result = $this->dekurzDocumentService->getAvailableDates((int) $data['patient_id'], $data['month']);

        return $this->success($result, 'Dostupné termíny boli načítané');
    }

    /**
     * Prefill dekurz from latest proposal AI.
     *
     * @group Documents
     * @urlParam patient int required Patient ID. Example: 1
     */
    public function prefillFromLatestProposal(Patient $patient)
    {
        try {
            $data = $this->aiPrefillService->buildFromLatestProposal($patient);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            report($e);
            return $this->error('Nepodarilo sa vygenerovať návrh textov dekurzu pomocou AI.', 500);
        }

        return $this->success($data, 'Návrh textov dekurzu bol úspešne vygenerovaný.');
    }

    /**
     * Improve dekurz text using AI.
     *
     * @group Documents
     * @urlParam patient int required Patient ID. Example: 1
     * @bodyParam text string required Text to improve.
     */
    public function improveText(Patient $patient, Request $request)
    {
        $request->validate([
            'text' => ['required', 'string', 'max:12000'],
        ]);

        try {
            $improved = $this->aiPrefillService->improveText((string) $request->input('text'));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            report($e);
            return $this->error('Nepodarilo sa vylepšiť text pomocou AI.', 500);
        }

        return $this->success(['improved_text' => $improved], 'Text bol úspešne vylepšený pomocou AI.');
    }

    /**
     * Preview dekurz document as HTML via Blade template.
     *
     * @group Documents
     * @urlParam document int required Document ID. Example: 1
     */
    public function preview(Document $document)
    {
        $document->loadMissing('user');

        $dekurzData = $this->dekurzDocumentService->findDekurzFileForDocument($document);
        if (!$dekurzData) {
            return $this->error('Dáta dekurzu sa nenašli', 404);
        }

        $signatureDataUri = $this->documentService->getUserSignatureDataUri((int) ($dekurzData['user_id'] ?? 0));

        return response()->view('pdf.dekurz', [
            'dekurzData' => $dekurzData,
            'signatureDataUri' => $signatureDataUri,
        ])->header('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Download dekurz PDF generated from Blade template.
     *
     * @group Documents
     * @urlParam document int required Document ID. Example: 1
     */
    public function download(Document $document)
    {
        $document->loadMissing('user');

        $pdfPath = $this->documentService->getTravelDocumentPdfPath($document);
        if (!$pdfPath || !Storage::disk('local')->exists($pdfPath)) {
            return $this->error('PDF dekurzu sa nenašlo', 500);
        }

        $downloadName = pathinfo($document->name, PATHINFO_FILENAME) . '.pdf';

        return response()->download(Storage::disk('local')->path($pdfPath), $downloadName, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * Get a signed public preview URL for dekurz document.
     *
     * @group Documents
     * @urlParam document int required Document ID. Example: 1
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
