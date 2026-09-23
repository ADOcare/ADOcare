<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Filters\ApiQuery;
use App\Http\Requests\SendDocumentsEmailRequest;
use App\Models\Document;
use App\Models\Patient;
use App\Models\User;
use App\Services\DocumentService;
use App\Http\Controllers\Api\KilometersExportController;
use App\Http\Controllers\Api\PointsExportController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class DocumentController extends Controller
{
    public function __construct(private DocumentService $service)
    {
    }

    /**
     * Create a company travel document (CP/DZC).
     *
     * @group Documents
     * @bodyParam type string required Document type (cp, dzc). Example: cp
     * @bodyParam branch_id int required Branch ID. Example: 1
     * @bodyParam period string required Period in YYYY-MM. Example: 2026-04
     * @response 201 {"data":{"document_id":1,"type":"cp"},"message":"Cestovný príkaz bol úspešne vytvorený"}
     */
    public function createCompanyTravelDocument(Request $request)
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:cp,dzc'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'period' => ['required', 'date_format:Y-m'],
        ]);

        try {
            $result = $this->service->createCompanyTravelDocument($validated, $request->user());
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(
            [
                'document_id' => $result['document']->id,
                'type' => $result['type'],
            ],
            $result['type'] === 'cp'
            ? 'Cestovný príkaz bol úspešne vytvorený'
            : 'Denný záznam ciest bol úspešne vytvorený',
            201
        );
    }

    /**
     * List travel documents for the authenticated user.
     *
     * @group Documents
     * @queryParam branch_id int optional Filter by branch ID. Example: 2
     * @queryParam period string optional Period in YYYY-MM. Example: 2026-04
     * @queryParam per_page int optional Items per page. Example: 25
     */
    public function indexTravelDocuments(Request $request)
    {
        $perPage = (int) $request->input('per_page', 25);
        $branchId = $request->input('branch_id');
        $branchId = is_numeric($branchId) ? (int) $branchId : null;
        $period = $request->input('period');

        $documents = $this->service->getTravelDocuments(
            Auth::id(),
            $branchId,
            $period,
            $perPage
        );

        return $this->success($documents, 'Dokumenty boli načítané');
    }

    /**
     * List travel documents for a company (manager/superadmin).
     *
     * @group Documents
     * @queryParam branch_ids string|array optional Comma-separated branch IDs. Example: 1,2
     * @queryParam period string optional Period in YYYY-MM. Example: 2026-04
     * @queryParam per_page int optional Items per page. Example: 25
     */
    public function indexTravelDocumentsForCompany(Request $request)
    {
        $perPage = (int) $request->input('per_page', 25);
        $branchIds = $request->input('branch_ids');
        $period = $request->input('period');

        $branchIdArray = [];
        if ($branchIds) {
            if (is_string($branchIds)) {
                $branchIdArray = array_map('intval', array_filter(explode(',', $branchIds)));
            } elseif (is_array($branchIds)) {
                $branchIdArray = array_map('intval', $branchIds);
            }
        }

        $documents = $this->service->getTravelDocumentsForCompany($branchIdArray, $period, $perPage);

        return $this->success($documents, 'Dokumenty boli načítané');
    }

    /**
     * Display a listing of documents for a patient.
     *
     * @group Documents
     * @urlParam patientId int required Patient ID. Example: 1
     */
    public function index(Request $request, $patientId)
    {
        $patient = Patient::findOrFail($patientId);
        $this->authorize('view', $patient);

        $query = Document::query()->where('patient_id', $patientId);

        $results = ApiQuery::apply(
            $request,
            $query,
            ['name', 'type', 'created_at'],
            [],
            ['sort' => '-created_at']
        );

        return $this->success($results, 'Dokumenty boli načítané');
    }

    /**
     * Store a newly created document.
     *
     * @group Documents
     * @urlParam patientId int required Patient ID. Example: 1
     * @bodyParam type string required Document type. Example: scan
     * @bodyParam file file required Uploaded file.
     * @response 201 {"data":{"document":{"id":1}},"message":"Dokument bol uložený"}
     */
    public function store(Request $request, $patientId)
    {
        $patient = Patient::findOrFail($patientId);
        $this->authorize('update', $patient);

        $validated = $request->validate([
            'file' => 'required|file|max:10240',
            'type' => 'required|string',
        ]);

        $file = $request->file('file');
        $path = $file->store("patients/{$patientId}/documents", 'local');

        $document = Document::create([
            'patient_id' => $patientId,
            'user_id' => Auth::id(),
            'type' => $validated['type'],
            'mime_type' => $file->getMimeType(),
            'name' => $file->getClientOriginalName(),
            'path' => $path,
        ]);

        return $this->success(['document' => $document], 'Dokument bol uložený', 201);
    }

    /**
     * Display the specified document.
     *
     * @group Documents
     * @urlParam document int required Document ID. Example: 1
     */
    public function show(Document $document)
    {
        $this->authorize('view', $document);

        return $this->success(['document' => $document]);
    }

    /**
     * Download the specified document.
     *
     * @group Documents
     * @urlParam document int required Document ID. Example: 1
     */
    public function download(Document $document)
    {
        $this->authorize('view', $document);

        $filePath = Storage::disk('local')->path($document->path);

        return response()->download($filePath, $document->name);
    }

    /**
     * Public, signed access to a document.
     *
     * @group Documents
     * @urlParam document int required Document ID. Example: 1
     * @queryParam format string optional Set to html for preview. Example: html
     * @queryParam download int optional Set to 1 for file download. Example: 1
     */
    public function publicDocument(Request $request, Document $document)
    {
        if (!Storage::disk('local')->exists($document->path)) {
            abort(404, 'Dokument nebol nájdený');
        }

        if ($request->query('download') === '1') {
            $format = $request->query('format', 'pdf');

            if ($format === 'csv' && $document->type === 'dzc') {
                return app(DZCDocumentController::class)->exportCsv($document);
            }

            if (in_array($document->type, ['kilometers_batch', 'points_batch'], true)) {
                $payload = $this->service->buildBatchDownloadPayload($document);
                if (!$payload) {
                    abort(404, 'TXT súbor pre dávku nebol nájdený');
                }

                $downloadRequest = Request::create('/', 'POST', $payload);
                $downloadRequest->setUserResolver(fn () => $document->user);

                return $document->type === 'kilometers_batch'
                    ? app(KilometersExportController::class)->download($downloadRequest)
                    : app(PointsExportController::class)->download($downloadRequest);
            }

            $pdfPath = $this->service->getTravelDocumentPdfPath($document);
            if (!$pdfPath || !Storage::disk('local')->exists($pdfPath)) {
                abort(500, 'Chyba pri generovaní PDF dokumentu');
            }

            $filePath = Storage::disk('local')->path($pdfPath);
            $downloadName = pathinfo($document->name, PATHINFO_FILENAME) . '.pdf';

            return response()->download($filePath, $downloadName);
        }

        if ($request->query('format') === 'html') {
            $preview = $this->service->getDocumentPreviewData($document);
            if ($preview) {
                return response()
                    ->view($preview['view'], $preview['data'])
                    ->header('Content-Type', 'text/html; charset=utf-8');
            }

            abort(404, 'Náhľad dokumentu nie je dostupný');
        }

        // Request expires is a string timestamp, convert to int for URL generation
        $expires = is_numeric($request->query('expires')) ? (int) $request->query('expires') : null;

        if (!$expires) {
            abort(400, 'Neplatný alebo chýbajúci parameter expires');
        }

        // Generate the signature specifically for the DATA endpoint to pass to the SPA
        $dataUrl = URL::temporarySignedRoute(
            'documents.public.data',
            \Carbon\Carbon::createFromTimestamp($expires),
            ['document' => $document->id]
        );

        $mainUrl = URL::temporarySignedRoute(
            'documents.public',
            \Carbon\Carbon::createFromTimestamp($expires),
            ['document' => $document->id]
        );


        // Extract signatures from the generated URLs to pass to the SPA for subsequent requests
        $query = [];
        parse_str(parse_url($dataUrl, PHP_URL_QUERY), $query);
        $data_signature = $query['signature'] ?? null;

        parse_str(parse_url($mainUrl, PHP_URL_QUERY), $query);
        $main_signature = $query['signature'] ?? null;

        return redirect()->route('spa', [
            'any' => "public/documents/{$document->id}",
            'main_signature' => $main_signature,
            'data_signature' => $data_signature,
            'expires' => $expires,
        ]);
    }


    /**
     * Get data for a public document.
     */
    public function publicDocumentData(Document $document)
    {
        $preview = $this->service->getDocumentPreviewData($document);
        if (!$preview) {
            abort(404, 'Údaje dokumentu nie sú dostupné');
        }

        return $this->success([
            'id' => $document->id,
            'type' => $document->type,
            'name' => $document->name,
            'payload' => $preview['data'],
        ]);
    }
    /**
     * Update the specified document.
     *
     * @group Documents
     * @urlParam document int required Document ID. Example: 1
     * @bodyParam type string optional Document type. Example: scan
     * @bodyParam name string optional Document name. Example: dokument.pdf
     */
    public function update(Request $request, Document $document)
    {
        $this->authorize('update', $document);

        $validated = $request->validate([
            'type' => 'sometimes|string',
            'name' => 'sometimes|string',
        ]);

        $document->update($validated);

        return $this->success(['document' => $document], 'Dokument bol aktualizovaný');
    }

    /**
     * Delete the specified document.
     *
     * @group Documents
     * @urlParam document int required Document ID. Example: 1
     */
    public function destroy(Document $document)
    {
        $this->authorize('delete', $document);

        $deleted = $this->service->deleteDocumentWithAssets($document);
        if (!$deleted) {
            \Log::error('Failed to delete document file', [
                'document_id' => $document->id,
                'path' => $document->path,
            ]);

            return $this->error('Nepodarilo sa odstrániť súbor z disku', 500, [
                'path' => $document->path,
            ]);
        }

        $document->delete();

        return $this->success(null, 'Dokument bol úspešne odstránený');
    }

    /**
     * Delete multiple documents.
     *
     * @group Documents
     * @bodyParam ids integer[] required Document IDs. Example: [1,2,3]
     */
    public function destroyMany(Request $request)
    {
        $ids = $request->input('ids', []);

        if (empty($ids)) {
            return $this->error('Neboli poskytnuté žiadne ID', 400);
        }

        $this->service->deleteManyDocumentsWithAssets($ids);

        return $this->success(null, 'Dokumenty boli úspešne odstránené');
    }

    /**
     * Email document and invoice links.
     *
     * @group Documents
     * @bodyParam email string required Recipient email. Example: user@example.com
     * @bodyParam ids integer[] optional Document IDs. Example: [1,2,3]
     * @bodyParam invoice_ids integer[] optional Invoice IDs. Example: [10,11]
     * @response 200 {"data":{"documents_count":2,"invoices_count":1,"total_count":3},"message":"Email bol úspešne odoslaný"}
     */
    public function emailDocuments(SendDocumentsEmailRequest $request)
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Používateľ nie je autentifikovaný', 401);
        }

        $validated = $request->validated();
        $documents = $this->service->buildDocumentLinks($validated['ids'] ?? [], $user);
        $invoices = $this->service->buildInvoiceLinks($validated['invoice_ids'] ?? [], $user);
        $items = array_merge($documents, $invoices);

        if (empty($items)) {
            return $this->error('Neboli nájdené žiadne dokumenty ani faktúry, ku ktorým máte prístup', 404);
        }

        $this->sendDocumentsEmail($user, $validated['email'], $items, 'Dokumenty');

        return $this->success([
            'documents_count' => count($documents),
            'invoices_count' => count($invoices),
            'total_count' => count($items),
        ], 'Email bol úspešne odoslaný');
    }

    /**
     * @param array<int, array<string, mixed>> $documents
     */
    private function sendDocumentsEmail(User $user, string $to, array $documents, string $subjectPrefix): void
    {
        $viewData = $this->buildDocumentsEmailViewData($user, $to, $documents);
        $subject = $subjectPrefix . ' - ' . ($viewData['companyName'] ?: 'ADOcare');

        $this->service->sendEmail($to, $subject, 'emails.document_links', $viewData);
    }

    /**
     * @param array<int, array<string, mixed>> $documents
     * @return array<string, mixed>
     */
    private function buildDocumentsEmailViewData(User $user, string $to, array $documents): array
    {
        $user->loadMissing('company', 'branches.company');

        $senderName = trim(implode(' ', array_filter([
            $user->title,
            $user->first_name,
            $user->last_name,
        ])));

        // Primary source is user's company relation, fallback to company from first assigned branch.
        $company = $user->company ?? $user->branches->first()?->company;

        return [
            'recipientName' => $to,
            'senderName' => $senderName ?: ($user->email ?? ''),
            'companyName' => $company?->name,
            'companyAddress' => $company?->address,
            'companyCity' => $company?->city,
            'companyEmail' => $company?->email,
            'companyPhone' => $company?->phone,
            'documents' => $documents,
        ];
    }

    /**
     * List documents for a patient by type.
     *
     * @group Documents
     * @urlParam patientId int required Patient ID. Example: 1
     * @urlParam type string required Document type. Example: proposal
     */
    public function getByType($patientId, $type)
    {
        $patient = Patient::findOrFail($patientId);
        $this->authorize('view', $patient);

        $documents = Document::query()->where('patient_id', $patientId)
            ->where('type', $type)
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->success(['documents' => $documents]);
    }

    /**
     * Check if a document for a given period already exists.
     * Supports two scenarios:
     * 1. Patient document: checks patient_id, user_id, type, and period
     * 2. User document: checks user_id, type, branch_id, and period
     *
     * @group Documents
     * @bodyParam type string required Document type. Example: proposal
     * @bodyParam date date required Document date. Example: 2026-04-01
     * @bodyParam patient_id int optional Patient ID. Example: 1
     * @bodyParam branch_id int optional Branch ID. Example: 2
     */
    public function checkExists(Request $request)
    {
        try {
            $validated = $request->validate([
                'type' => 'required|string',
                'date' => 'required|date',
                'patient_id' => 'nullable|numeric',
                'branch_id' => 'nullable|numeric',
            ]);

            return $this->success($this->service->documentExists($validated, Auth::id()));
        } catch (\Exception $e) {
            \Log::error('Document checkExists error: ' . $e->getMessage(), [
                'request' => $request->all(),
                'exception' => $e,
            ]);

            return $this->error('Overenie existencie dokumentu zlyhalo', 500, [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
