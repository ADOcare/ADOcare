<?php

namespace App\Services;

use App\Http\Filters\ApiQuery;
use App\Mail\GenericEmail;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DocumentService
{
    public function createCompanyTravelDocument(array $data, User $user): array
    {
        $branch = Branch::findOrFail((int) $data['branch_id']);

        if ($user->company_id && (int) $user->company_id !== (int) $branch->company_id) {
            throw new HttpException(403, 'Pobočka nepatrí do vašej spoločnosti.');
        }

        $period = Carbon::createFromFormat('Y-m', $data['period']);
        $start = $period->copy()->startOfMonth()->toDateString();
        $end = $period->copy()->endOfMonth()->toDateString();

        if ($data['type'] === 'cp') {
            [$document] = app(CPDocumentService::class)->createCp([
                'start' => $start,
                'end' => $end,
                'branch_id' => $branch->id,
                'job_title' => 'Manažér',
                'trip_purpose' => 'Pracovné stretnutia',
            ], $user);

            return ['document' => $document, 'type' => 'cp'];
        }

        [$document] = app(DZCDocumentService::class)->createManagerDzcFromVisitLocations([
            'period' => $period->format('Y-m'),
            'branch_id' => $branch->id,
        ], $user);

        return ['document' => $document, 'type' => 'dzc'];
    }

    public function getTravelDocuments(int $userId, ?int $branchId, ?string $period, int $perPage = 25): LengthAwarePaginator
    {
        $query = Document::query()
            ->whereIn('type', ['cp', 'dzc'])
            ->where('user_id', $userId);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        if ($period) {
            $query->where('period', Carbon::parse($period)->format('Y-m'));
        }

        return $query
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    public function getTravelDocumentsForCompany(?array $branchIds, ?string $period, int $perPage = 25): LengthAwarePaginator
    {
        $query = Document::query()
            ->with([
                'user:id,title,first_name,last_name',
                'branch:id,address',
            ])
            ->whereIn('type', ['cp', 'dzc']);

        if (!empty($branchIds)) {
            $query->whereIn('branch_id', $branchIds);
        }

        if ($period) {
            $query->where('period', Carbon::parse($period)->format('Y-m'));
        }

        $documents = $query
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        $documents->getCollection()->transform(function (Document $document) {
            $representative = $document->user;
            $branch = $document->branch;

            $userName = trim(implode(' ', array_filter([
                $representative?->title,
                $representative?->first_name,
                $representative?->last_name,
            ])));

            return [
                'id' => $document->id,
                'name' => $document->name,
                'type' => $document->type,
                'mime_type' => $document->mime_type,
                'path' => $document->path,
                'created_at' => $document->created_at?->toDateTimeString(),
                'updated_at' => $document->updated_at?->toDateTimeString(),
                'period' => $document->period,
                'created_by_user' => $userName ?: null,
                'created_by_branch' => $branch?->address ?: null,
            ];
        });

        return $documents;
    }

    /**
     * Build signed public links for documents the user can view.
     *
     * @param array<int> $ids
     * @param array<int, string>|null $types
     * @return array<int, array<string, mixed>>
     */
    public function buildDocumentLinks(array $ids, User $user, int $ttlMinutes = 10080, ?array $types = null): array
    {
        if (empty($ids)) {
            return [];
        }

        $query = Document::query()->whereIn('id', $ids);

        if (!empty($types)) {
            $query->whereIn('type', $types);
        }

        $documents = $query
            ->with(['patient.branch', 'branch'])
            ->get()
            ->filter(fn(Document $document) => $user->can('view', $document));

        if ($documents->isEmpty()) {
            return [];
        }

        return $documents->map(function (Document $document) use ($ttlMinutes) {
            return [
                'id' => $document->id,
                'name' => $document->name,
                'type' => $document->type,
                'period' => $document->period,
                'view_link' => $this->generatePublicDocumentLink($document, $ttlMinutes, false),
                'download_link' => $this->generatePublicDocumentLink($document, $ttlMinutes, true),
            ];
        })->values()->toArray();
    }

    /**
     * Build signed public links for invoices the user can view.
     *
     * @param array<int> $ids
     * @return array<int, array<string, mixed>>
     */
    public function buildInvoiceLinks(array $ids, User $user, int $ttlMinutes = 10080): array
    {
        if (empty($ids)) {
            return [];
        }

        $invoices = Invoice::query()
            ->whereIn('id', $ids)
            ->get()
            ->filter(fn(Invoice $invoice) => $user->can('view', $invoice));

        if ($invoices->isEmpty()) {
            return [];
        }

        return $invoices->map(function (Invoice $invoice) use ($ttlMinutes) {
            $displayName = $invoice->invoice_number
                ? 'Faktúra ' . $invoice->invoice_number
                : ($invoice->name ?: 'Faktúra');

            return [
                'id' => $invoice->id,
                'name' => $displayName,
                'type' => 'invoice',
                'period' => $invoice->period,
                'view_link' => $this->generatePublicInvoiceLink($invoice, $ttlMinutes, false),
                'download_link' => $this->generatePublicInvoiceLink($invoice, $ttlMinutes, true),
            ];
        })->values()->toArray();
    }

    public function sendEmail(string $to, string $subject, string $view, array $viewData): void
    {
        Mail::to($to)->send(new GenericEmail($subject, $viewData, $view));
    }

    public function generatePublicDocumentLink(Document $document, int $ttlMinutes = 10080, bool $download = false): string
    {
        $parameters = ['document' => $document->id];
        if ($download) {
            $parameters['download'] = 1;
        }

        return URL::temporarySignedRoute(
            'documents.public',
            now()->addMinutes($ttlMinutes),
            $parameters
        );
    }

    /**
     * Generate a signed public link for invoice preview or download.
     */
    public function generatePublicInvoiceLink(Invoice $invoice, int $ttlMinutes = 10080, bool $download = false): string
    {
        $parameters = ['invoice' => $invoice->id];
        if ($download) {
            $parameters['download'] = 1;
        }

        return URL::temporarySignedRoute(
            'invoices.public',
            now()->addMinutes($ttlMinutes),
            $parameters
        );
    }

    public function deleteDocumentWithAssets(Document $document): bool
    {
        $this->deleteScanAssetsIfAny($document);
        $this->deleteTravelDocumentPdfCache($document);

        if (Storage::disk('local')->exists($document->path)) {
            return Storage::disk('local')->delete($document->path);
        }

        return true;
    }

    public function deleteManyDocumentsWithAssets(array $ids): void
    {
        $documents = Document::query()->whereIn('id', $ids)->get();

        foreach ($documents as $document) {
            if (!$document instanceof Document) {
                continue;
            }

            $this->deleteScanAssetsIfAny($document);

            if (Storage::disk('local')->exists($document->path)) {
                if (!Storage::disk('local')->delete($document->path)) {
                    \Log::error('Failed to delete document file (batch)', [
                        'document_id' => $document->id,
                        'path' => $document->path,
                    ]);
                }
            } else {
                \Log::warning('Document file not found on disk (batch)', [
                    'document_id' => $document->id,
                    'expected_path' => $document->path,
                ]);
            }
        }

        Document::whereIn('id', $ids)->delete();
    }

    public function documentExists(array $data, int $userId): array
    {
        $date = new \DateTime($data['date']);
        $period = $date->format('Y-m');
        $type = $data['type'];
        $patientId = $data['patient_id'] ?? null;
        $branchId = $data['branch_id'] ?? null;

        if ($patientId) {
            $document = Document::where('patient_id', $patientId)
                ->where('user_id', $userId)
                ->where('type', $type)
                ->where('period', $period)
                ->first();
        } else {
            $query = Document::where('user_id', $userId)
                ->where('type', $type)
                ->where('period', $period);

            if ($branchId) {
                $query->where('branch_id', $branchId);
            }

            $document = $query->first();
        }

        return [
            'exists' => $document !== null,
            'document_id' => $document?->id,
        ];
    }

    public function getPatientDocuments(Request $request, int $patientId)
    {
        $query = Document::query()->where('patient_id', $patientId);

        return ApiQuery::apply(
            $request,
            $query,
            ['name', 'type', 'created_at'],
            [],
            ['sort' => '-created_at']
        );
    }

    public function storePatientDocument(Request $request, int $patientId): Document
    {
        $file = $request->file('file');
        $path = $file->store("patients/{$patientId}/documents", 'local');

        return Document::create([
            'patient_id' => $patientId,
            'user_id' => auth()->id(),
            'type' => $request->input('type'),
            'mime_type' => $file->getMimeType(),
            'name' => $file->getClientOriginalName(),
            'path' => $path,
        ]);
    }

    public function getTravelDocumentPdfPath(Document $document): ?string
    {
        $cachePath = $this->getTravelDocumentPdfCachePath($document);
        $disk = Storage::disk('local');


        if ($disk->exists($cachePath)) {
            // $disk->delete($cachePath);
            // return $cachePath;
        }

        $pdfData = $this->buildTravelPdfAttachment($document);
        if (!$pdfData) {
            return null;
        }

        $disk->put($cachePath, $pdfData['data']);
        return $cachePath;
    }

    /**
     * Resolve preview view and payload for a document.
     *
     * @return array{view: string, data: array<string, mixed>, filename?: string}|null
     */
    public function getDocumentPreviewData(Document $document): ?array
    {
        return $this->buildDocumentViewData($document);
    }

    /**
     * Build sheet data for batch statement templates.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function buildStatementSheetFromBatchPayload(array $payload, string $type): array
    {
        $meta = (array) ($payload['meta'] ?? []);
        $batchNumber = (string) ($payload['batchNumber'] ?? '0');
        $period = (array) ($payload['period'] ?? []);

        return [
            'fileType' => $type === 'kilometers' ? 'vykázané kilometre' : 'vykázané body',
            'fileName' => (string) ($meta['fileName'] ?? ('davka.' . $batchNumber . '.txt')),
            'kilometers' => (string) ($meta['totalKilometers'] ?? ''),
            'amount' => (string) ($meta['amount'] ?? '0'),
            'periodFrom' => (string) ($period[0] ?? ''),
            'periodTo' => (string) ($period[1] ?? ''),
            'performedBy' => (string) ($meta['performedBy'] ?? ''),
            'performedDate' => (string) ($meta['performedDate'] ?? now()->toDateString()),
            'companyName' => (string) ($meta['companyName'] ?? ''),
            'branchName' => (string) ($meta['branchName'] ?? ''),
            'insuranceName' => (string) ($meta['insuranceName'] ?? ''),
        ];
    }

    public function getUserSignatureDataUri(?int $userId): ?string
    {
        return $this->loadUserSignatureDataUri($userId);
    }

    public function getCompanyStampDataUri(?int $companyId): ?string
    {
        return $this->loadCompanyStampDataUri($companyId);
    }

    /**
     * Build payload for batch TXT download from stored document data.
     *
     * @return array<string, mixed>|null
     */
    public function buildBatchDownloadPayload(Document $document): ?array
    {
        $payload = match ($document->type) {
            'kilometers_batch' => app(KilometersBatchDocumentService::class)->getKilometersBatchPayload($document),
            'points_batch' => app(PointsBatchDocumentService::class)->getPointsBatchPayload($document),
            default => null,
        };

        if (!is_array($payload)) {
            return null;
        }

        $downloadPayload = [
            'batchNumber' => (string) ($payload['batchNumber'] ?? ''),
            'batchType' => ['code' => (string) data_get($payload, 'batchType.code', 'N')],
            'insurance' => ['id' => (int) data_get($payload, 'insurance.id', 0)],
            'period' => (array) ($payload['period'] ?? []),
            'branch' => ['id' => (int) data_get($payload, 'branch.id', 0)],
            'claimBatchId' => data_get($payload, 'claimBatchId'),
        ];

        if (! empty($payload['pointIds'])) {
            $downloadPayload['pointIds'] = (array) $payload['pointIds'];
        }
        if (! empty($payload['patients'])) {
            $downloadPayload['patients'] = (array) $payload['patients'];
        }

        return $downloadPayload;
    }
    /**
     * Get or generate invoice PDF path.
     * Generates PDF from Blade template if it doesn't exist.
     */
    public function getInvoicePdfPath(Invoice $invoice): ?string
    {
        $pdfPath = $this->getInvoicePdfCachePath($invoice);
        $disk = Storage::disk('local');

        // if ($disk->exists($pdfPath)) {
        //     return $pdfPath;
        // }

        return $this->buildAndSaveInvoicePdf($invoice, $pdfPath);
    }

    private function getInvoicePdfCachePath(Invoice $invoice): string
    {
        return sprintf('invoices/pdf/%d.pdf', $invoice->id);
    }

    private function buildAndSaveInvoicePdf(Invoice $invoice, string $pdfPath): ?string
    {
        $invoiceData = json_decode(
            (string) Storage::disk('local')->get($invoice->path),
            true
        );

        if (!is_array($invoiceData)) {
            return null;
        }

        try {
            $pdf = Pdf::loadView('pdf.invoice', [
                'invoiceData' => $invoiceData,
            ])->setPaper('a4', 'portrait');

            Storage::disk('local')->put($pdfPath, $pdf->output());
            return $pdfPath;
        } catch (\Exception $e) {
            Log::error('Failed to generate invoice PDF', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
    private function getTravelDocumentPdfCachePath(Document $document): string
    {
        return sprintf('documents/pdf/%s/%d.pdf', $document->type, $document->id);
    }

    private function deleteTravelDocumentPdfCache(Document $document): void
    {
        $cachePath = $this->getTravelDocumentPdfCachePath($document);
        if (Storage::disk('local')->exists($cachePath)) {
            Storage::disk('local')->delete($cachePath);
        }
    }

    private function buildTravelPdfAttachment(Document $document): ?array
    {
        $preview = $this->buildDocumentViewData($document);
        if (!$preview) {
            return null;
        }

        $pdf = Pdf::loadView($preview['view'], $preview['data'])->setPaper('a4', 'portrait');

        return [
            'data' => $pdf->output(),
            'name' => $preview['filename'] ?? $this->buildDefaultPdfFilename($document),
            'mime' => 'application/pdf',
        ];
    }

    /**
     * @return array{view: string, data: array<string, mixed>, filename?: string}|null
     */
    private function buildDocumentViewData(Document $document): ?array
    {
        return match ($document->type) {
            'cp' => $this->buildCpViewData($document),
            'dzc' => $this->buildDzcViewData($document),
            'proposal' => $this->buildProposalViewData($document),
            'agreement' => $this->buildAgreementViewData($document),
            'leave' => $this->buildLeaveViewData($document),
            'record' => $this->buildRecordViewData($document),
            'dekurz' => $this->buildDekurzViewData($document),
            'scan' => $this->buildScanViewData($document),
            'kilometers_batch' => $this->buildBatchViewData($document, 'kilometers'),
            'points_batch' => $this->buildBatchViewData($document, 'points'),
            default => null,
        };
    }

    private function buildCpViewData(Document $document): ?array
    {
        $payload = app(CPDocumentService::class)->getCpPayload($document);
        if (!$payload) {
            return null;
        }

        $signatureDataUri = $this->loadUserSignatureDataUri((int) ($payload['representative_id'] ?? 0));

        return [
            'view' => 'pdf.travel_cp',
            'data' => [
                'cpData' => $payload,
                'signatureDataUri' => $signatureDataUri,
            ],
            'filename' => $this->buildTravelPdfFilename('CP', $payload, $document),
        ];
    }

    private function buildDzcViewData(Document $document): ?array
    {
        $payload = app(DZCDocumentService::class)->getDzcPayload($document);
        if (!$payload) {
            return null;
        }

        $signatureDataUri = $this->loadUserSignatureDataUri((int) ($payload['user_id'] ?? 0));

        return [
            'view' => 'pdf.travel_dzc',
            'data' => [
                'dzcData' => $payload,
                'signatureDataUri' => $signatureDataUri,
            ],
            'filename' => $this->buildTravelPdfFilename('DZP', $payload, $document),
        ];
    }

    private function buildProposalViewData(Document $document): ?array
    {
        $payload = app(ProposalDocumentService::class)->getProposalPayload($document);
        if (!$payload) {
            return null;
        }

        return [
            'view' => 'pdf.proposal',
            'data' => [
                'proposalData' => $payload,
                'stampDataUri' => $this->loadCompanyStampDataUri((int) ($payload['company_id'] ?? 0)),
                'signatureDataUri' => $this->loadUserSignatureDataUri((int) ($payload['representative_id'] ?? 0)),
            ],
        ];
    }

    private function buildAgreementViewData(Document $document): ?array
    {
        $payload = app(AgreementDocumentService::class)->getAgreementPayload($document);
        if (!$payload) {
            return null;
        }

        return [
            'view' => 'pdf.agreement',
            'data' => [
                'agreementData' => $payload,
                'stampDataUri' => $this->loadCompanyStampDataUri((int) ($payload['company_id'] ?? 0)),
                'signatureDataUri' => $this->loadUserSignatureDataUri((int) ($payload['branch_representative_id'] ?? 0)),
            ],
        ];
    }

    private function buildLeaveViewData(Document $document): ?array
    {
        $payload = app(LeaveDocumentService::class)->findLeaveFileForDocument($document);
        if (!$payload) {
            return null;
        }

        return [
            'view' => 'pdf.leave',
            'data' => [
                'leaveData' => $payload,
                'signatureDataUri' => $this->loadUserSignatureDataUri((int) ($payload['user_id'] ?? 0)),
            ],
        ];
    }

    private function buildRecordViewData(Document $document): ?array
    {
        $payload = app(RecordDocumentService::class)->findRecordFileForDocument($document);

        if (!$payload) {
            return null;
        }

        return [
            'view' => 'pdf.record',
            'data' => [
                'recordData' => $payload,
                'signatureDataUri' => $this->loadUserSignatureDataUri((int) ($payload['user_id'] ?? 0)),
            ],
        ];
    }

    private function buildDekurzViewData(Document $document): ?array
    {
        $payload = app(DekurzDocumentService::class)->findDekurzFileForDocument($document);
        if (!$payload) {
            return null;
        }

        if (!array_key_exists('patient_birth_number', $payload)) {
            $payload['patient_birth_number'] = $payload['patient_personal_number'] ?? '';
        }

        if (!array_key_exists('visits', $payload)) {
            $payload['visits'] = collect($payload['days'] ?? [])
                ->map(fn(array $day) => [
                    'date' => $day['date'] ?? null,
                    'time_from' => $day['terrain_time'] ?? null,
                    'time_to' => $day['administrative_time'] ?? null,
                    'note' => $day['text'] ?? '',
                    'terrain_time' => $day['terrain_time'] ?? null,
                    'administrative_time' => $day['administrative_time'] ?? null,
                ])
                ->all();
        }

        return [
            'view' => 'pdf.dekurz',
            'data' => [
                'dekurzData' => $payload,
                'signatureDataUri' => $this->loadUserSignatureDataUri((int) ($payload['user_id'] ?? 0)),
            ],
        ];
    }

    private function buildScanViewData(Document $document): ?array
    {
        $payload = app(ScanDocumentService::class)->getScanPayload($document);
        if (!$payload) {
            return null;
        }

        return [
            'view' => 'pdf.scan',
            'data' => [
                'scanData' => [
                    'patient_name' => $payload['patient_name'] ?? '',
                    'patient_birth_number' => $payload['patient_birth_number'] ?? '',
                    'date' => $payload['scanned_at'] ?? '',
                    'images' => $payload['image_paths'] ?? [],
                ],
            ],
        ];
    }

    private function buildBatchViewData(Document $document, string $type): ?array
    {
        $payload = $type === 'kilometers'
            ? app(KilometersBatchDocumentService::class)->getKilometersBatchPayload($document)
            : app(PointsBatchDocumentService::class)->getPointsBatchPayload($document);

        if (!$payload) {
            return null;
        }

        $sheet = $this->buildStatementSheetFromBatchPayload($payload, $type);

        return [
            'view' => 'pdf.statement',
            'data' => ['sheet' => $sheet],
        ];
    }

    private function buildDefaultPdfFilename(Document $document): string
    {
        $base = pathinfo((string) $document->name, PATHINFO_FILENAME);
        if ($base === '') {
            $base = (string) ($document->type ?? 'document');
        }

        return $this->sanitizeFilenamePart($base) . '.pdf';
    }

    private function loadUserSignatureDataUri(?int $userId): ?string
    {
        if (!$userId) {
            return null;
        }

        $user = User::find($userId);
        $signaturePath = $user?->signature_path;

        if (!$signaturePath || !Storage::disk('local')->exists($signaturePath)) {
            return null;
        }

        $binary = Storage::disk('local')->get($signaturePath);
        if ($binary === null || $binary === '') {
            return null;
        }

        return 'data:image/png;base64,' . base64_encode($binary);
    }

    private function loadCompanyStampDataUri(?int $companyId): ?string
    {
        if (!$companyId) {
            return null;
        }

        $company = Company::find($companyId);
        $stampPath = $company?->stamp_path;

        if (!$stampPath || !Storage::disk('local')->exists($stampPath)) {
            return null;
        }

        $binary = Storage::disk('local')->get($stampPath);
        if ($binary === null || $binary === '') {
            return null;
        }

        return 'data:image/png;base64,' . base64_encode($binary);
    }

    private function buildTravelPdfFilename(string $prefix, array $payload, Document $document): string
    {
        $period = (string) ($payload['period'] ?? $document->period ?? 'unknown-period');

        if ($period === 'unknown-period') {
            $month = (string) ($payload['month'] ?? '');
            $year = (string) ($payload['year'] ?? '');
            if ($month !== '' && $year !== '') {
                $period = $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT);
            }
        }

        $rawUserName = trim(implode(' ', array_filter([
            $document->user->last_name ?? null,
        ])));

        if ($rawUserName === '') {
            $rawUserName = 'unknown-user';
        }

        $normalizedUserName = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $rawUserName);
        if ($normalizedUserName === false || trim($normalizedUserName) === '') {
            $normalizedUserName = $rawUserName;
        }

        return $this->sanitizeFilenamePart($prefix)
            . '_' . $this->sanitizeFilenamePart($period)
            . '_' . $this->sanitizeFilenamePart($normalizedUserName)
            . '.pdf';
    }

    private function sanitizeFilenamePart(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/', '_', $value) ?? $value;
        $value = preg_replace('/[^A-Za-z0-9_\-.]/', '', $value) ?? $value;

        return $value === '' ? 'unknown' : $value;
    }

    private function deleteScanAssetsIfAny(Document $document): void
    {
        if ($document->type !== 'scan') {
            return;
        }

        $sessionId = null;

        if (Storage::disk('local')->exists($document->path)) {
            try {
                $raw = Storage::disk('local')->get($document->path);
                $json = json_decode($raw, true);
                $sessionId = (int) ($json['scan_session_id'] ?? 0);
            } catch (\Throwable $e) {
                \Log::warning('Failed to parse scan document JSON for cleanup', [
                    'document_id' => $document->id,
                    'path' => $document->path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (!$sessionId) {
            return;
        }

        $scanDir = "scans/{$sessionId}";
        if (Storage::disk('local')->exists($scanDir)) {
            Storage::disk('local')->deleteDirectory($scanDir);
        }
    }
}
