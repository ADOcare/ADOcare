<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Filters\ApiQuery;
use App\Http\Resources\BaseCollection;
use App\Models\Document;
use App\Models\Branch;
use App\Services\AgreementDocumentService;
use App\Services\DocumentService;
use App\Http\Requests\StoreAgreementRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;


class AgreementDocumentController extends Controller
{
    public function __construct(private AgreementDocumentService $service)
    {
    }

    /**
     * Store a new agreement document and its JSON payload.
     *
     * @group Documents
     * @bodyParam date date required Agreement date. Example: 2024-01-01
     * @bodyParam patient_id integer required Patient ID. Example: 1
     * @bodyParam branch_id integer required Branch ID. Example: 2
     * @response 201 {"document_id":123, "agreement": {...}}
     *
     * @param  \App\Http\Requests\StoreAgreementRequest  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreAgreementRequest $request)
    {
        [$document, $payload] = $this->service->createAgreement($request->validated(), $request->user());

        return $this->success([
            'document_id' => $document->id,
            'agreement' => $payload,
        ], 'Dohoda o poskytovaní zdravotnej starostlivosti bola úspešne vytvorená', 201);
    }

    /** Preview an agreement from unsaved form data. */
    public function previewDraft(StoreAgreementRequest $request)
    {
        $payload = $this->service->previewAgreement($request->validated());
        $documentService = app(DocumentService::class);

        return response()->view('pdf.agreement', [
            'agreementData' => $payload,
            'stampDataUri' => $documentService->getCompanyStampDataUri((int) ($payload['company_id'] ?? 0)),
            'signatureDataUri' => $documentService->getUserSignatureDataUri((int) ($payload['branch_representative_id'] ?? 0)),
        ])->header('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Return agreement payload for a document.
     * Uses route-model binding (accepts `Document $document` instead of an id).
     *
     * @group Documents
     * @urlParam document int required Document ID. Example: 123
     * @response 200 {"document": {...}, "agreement_data": {...}}
     */
    public function show(Document $document)
    {
        $document->loadMissing('patient');

        $payload = $this->service->getAgreementPayload($document);
        if (!$payload) {
            return $this->error('Dáta dohody sa nenašli', 404);
        }

        return $this->success([
            'document' => $document,
            'agreement_data' => $payload,
        ]);
    }

    /**
     * Preview agreement document as HTML via Blade template.
     *
     * @group Documents
     */
    public function preview(Document $document)
    {
        $document->loadMissing('user');

        $payload = $this->service->getAgreementPayload($document);
        if (!$payload) {
            return $this->error('Dáta dohody sa nenašli', 404);
        }

        $stampDataUri = app(DocumentService::class)->getCompanyStampDataUri((int) ($payload['company_id'] ?? 0));
        $signatureDataUri = app(DocumentService::class)->getUserSignatureDataUri((int) ($payload['branch_representative_id'] ?? 0));

        return response()->view('pdf.agreement', [
            'agreementData' => $payload,
            'stampDataUri' => $stampDataUri,
            'signatureDataUri' => $signatureDataUri,
        ])->header('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Download agreement PDF generated from Blade template.
     *
     * @group Documents
     */
    public function download(Document $document)
    {
        $document->loadMissing('user');

        $pdfPath = app(DocumentService::class)->getTravelDocumentPdfPath($document);
        if (!$pdfPath || !Storage::disk('local')->exists($pdfPath)) {
            return $this->error('PDF dohody sa nenašlo', 500);
        }

        $downloadName = pathinfo($document->name, PATHINFO_FILENAME) . '.pdf';

        return response()->download(Storage::disk('local')->path($pdfPath), $downloadName, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * Get a signed public preview URL for agreement document.
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
