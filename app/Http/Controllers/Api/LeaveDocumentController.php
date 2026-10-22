<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLeaveDocumentRequest;
use App\Models\Document;
use App\Models\Patient;
use App\Models\User;
use App\Services\LeaveDocumentService;
use App\Services\DocumentService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Handles leave document endpoints.
 */
class LeaveDocumentController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct(private LeaveDocumentService $service)
    {
    }

    /**
     * Store a new leave document.
     */
    public function store(StoreLeaveDocumentRequest $request)
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->error('Používateľ nie je autentifikovaný', 401);
        }

        $document = $this->service->create($request->validated(), $user);

        return $this->success([
            'document_id' => $document->id,
        ], 'Prepúšťacia správa bola úspešne vytvorená', 201);
    }

    /**
     * Preview a leave document from unsaved form data.
     */
    public function previewDraft(StoreLeaveDocumentRequest $request)
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->error('Používateľ nie je autentifikovaný', 401);
        }

        $leaveData = $this->service->preview($request->validated(), $user);
        $documentService = app(DocumentService::class);

        return response()->view('pdf.leave', [
            'leaveData' => $leaveData,
            'stampDataUri' => $documentService->getCompanyStampDataUri((int) ($leaveData['company_id'] ?? 0)),
            'signatureDataUri' => $documentService->getUserSignatureDataUri((int) ($leaveData['user_id'] ?? 0)),
        ])->header('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Show leave document payload for the provided document id.
     */
    public function show(Document $document)
    {
        $this->authorize('view', $document);

        $document->loadMissing(['patient']);
        $leaveFile = $this->service->findLeaveFileForDocument($document);

        if (!$leaveFile) {
            return $this->error('Dáta prepúšťacej správy sa nenašli', 404);
        }

        return $this->success([
            'document' => $document,
            'leave_data' => $leaveFile,
        ], 'Prepúšťacia správa bola načítaná');
    }

    /**
     * Show latest leave document payload by patient id.
     */
    public function latestByPatient(Patient $patient)
    {
        $this->authorize('view', $patient);

        $document = $this->service->findLatestDocumentByPatientId($patient->id);

        if (!$document) {
            return $this->error('Prepúšťacia správa sa nenašla', 404);
        }

        $leaveFile = $this->service->findLeaveFileForDocument($document);

        if (!$leaveFile) {
            return $this->error('Dáta prepúšťacej správy sa nenašli', 404);
        }

        return $this->success([
            'document_id' => $document->id,
            'leave_data' => $leaveFile,
        ], 'Najnovšia prepúšťacia správa bola načítaná');
    }

    /**
     * Preview leave document as HTML via Blade template.
     *
     * @group Documents
     */
    public function preview(Document $document)
    {
        $document->loadMissing('user');

        $leaveData = $this->service->findLeaveFileForDocument($document);
        if (!$leaveData) {
            return $this->error('Dáta prepúšťacej správy sa nenašli', 404);
        }

        $signatureDataUri = app(DocumentService::class)->getUserSignatureDataUri((int) ($leaveData['user_id'] ?? 0));

        return response()->view('pdf.leave', [
            'leaveData' => $leaveData,
            'signatureDataUri' => $signatureDataUri,
        ])->header('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Download leave PDF generated from Blade template.
     *
     * @group Documents
     */
    public function download(Document $document)
    {
        $document->loadMissing('user');

        $pdfPath = app(DocumentService::class)->getTravelDocumentPdfPath($document);
        if (!$pdfPath || !Storage::disk('local')->exists($pdfPath)) {
            return $this->error('PDF prepúšťacej správy sa nenašlo', 500);
        }

        $downloadName = pathinfo($document->name, PATHINFO_FILENAME) . '.pdf';

        return response()->download(Storage::disk('local')->path($pdfPath), $downloadName, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * Get a signed public preview URL for leave document.
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
