<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRecordDocumentRequest;
use App\Models\Document;
use App\Models\Patient;
use App\Models\User;
use App\Services\RecordDocumentService;
use App\Services\DocumentService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class RecordDocumentController extends Controller
{
    /**
     * Store a new record document.
     *
     * @group Documents
     * @bodyParam patient_id int required Patient ID. Example: 1
     * @bodyParam date date required Record date. Example: 2026-04-01
     * @response 201 {"data":{"document_id":1},"message":"Ošetrovateľský záznam bol úspešne vytvorený"}
     */
    public function store(StoreRecordDocumentRequest $request, RecordDocumentService $service)
    {
        $document = $service->create($request->validated(), Auth::user());

        return $this->success([
            'document_id' => $document->id,
        ], 'Ošetrovateľský záznam bol úspešne vytvorený', 201);
    }

    /**
     * Preview a record document from unsaved form data.
     */
    public function previewDraft(StoreRecordDocumentRequest $request, RecordDocumentService $service)
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->error('Používateľ nie je autentifikovaný', 401);
        }

        $recordData = $service->preview($request->validated(), $user);
        $signatureDataUri = app(DocumentService::class)->getUserSignatureDataUri((int) $user->id);

        return response()->view('pdf.record', [
            'recordData' => $recordData,
            'signatureDataUri' => $signatureDataUri,
        ])->header('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Show record document payload.
     *
     * @group Documents
     * @urlParam document int required Document ID. Example: 1
     */
    public function show(Document $document, RecordDocumentService $service)
    {
        $document->loadMissing('patient');

        $recordFile = $service->findRecordFileForDocument($document);
        if (!$recordFile) {
            return $this->error('Dáta ošetrovateľského záznamu sa nenašli', 404);
        }

        return $this->success([
            'document' => $document,
            'record_data' => $recordFile,
        ]);
    }

    /**
     * Show latest record document for a patient.
     *
     * @group Documents
     * @urlParam patient int required Patient ID. Example: 1
     */
    public function latestByPatient(Patient $patient, RecordDocumentService $service)
    {
        $document = Document::where('patient_id', $patient->id)
            ->where('type', 'record')
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        if (!$document) {
            return $this->success([
                'document_id' => null,
                'record_data' => null,
            ], 'Pacient nemá uložený ošetrovateľský záznam.');
        }

        $recordFile = $service->findRecordFileForDocument($document);
        if (!$recordFile) {
            return $this->success([
                'document_id' => $document->id,
                'record_data' => null,
            ], 'Dáta posledného ošetrovateľského záznamu sa nenašli.');
        }

        return $this->success([
            'document_id' => $document->id,
            'record_data' => $recordFile,
        ], 'Najnovší ošetrovateľský záznam bol načítaný');
    }

    /**
     * Preview record document as HTML via Blade template.
     *
     * @group Documents
     * @urlParam document int required Document ID. Example: 1
     */
    public function preview(Document $document, RecordDocumentService $service)
    {
        $document->loadMissing('user');

        $recordData = $service->findRecordFileForDocument($document);
        if (!$recordData) {
            return $this->error('Dáta ošetrovateľského záznamu sa nenašli', 404);
        }

        $signatureDataUri = app(DocumentService::class)->getUserSignatureDataUri((int) ($recordData['user_id'] ?? 0));

        return response()->view('pdf.record', [
            'recordData' => $recordData,
            'signatureDataUri' => $signatureDataUri,
        ])->header('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Download record PDF generated from Blade template.
     *
     * @group Documents
     * @urlParam document int required Document ID. Example: 1
     */
    public function download(Document $document)
    {
        $document->loadMissing('user');

        $pdfPath = app(DocumentService::class)->getTravelDocumentPdfPath($document);
        if (!$pdfPath || !Storage::disk('local')->exists($pdfPath)) {
            return $this->error('PDF ošetrovateľského záznamu sa nenašlo', 500);
        }

        $downloadName = pathinfo($document->name, PATHINFO_FILENAME) . '.pdf';

        return response()->download(Storage::disk('local')->path($pdfPath), $downloadName, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * Get a signed public preview URL for record document.
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
