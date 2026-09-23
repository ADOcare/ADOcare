<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePointsBatchRequest;
use App\Models\Document;
use App\Services\PointsBatchDocumentService;
use App\Services\DocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class PointsBatchDocumentController extends Controller
{
    public function __construct(private PointsBatchDocumentService $service)
    {
    }

    /**
     * Store a new points batch document.
     *
     * @group Documents
     * The batch number is generated automatically as UUMMPP.
     * @bodyParam batchType.code string required Batch type code. Example: N
     * @bodyParam insurance.id int required Insurance company ID. Example: 3
     * @bodyParam period array required Period range. Example: ["2026-04-01","2026-04-30"]
     * @bodyParam user.id int required User ID. Example: 10
     * @bodyParam branch.id int required Branch ID. Example: 2
     * @bodyParam company.id int required Company ID. Example: 1
     * @response 201 {"data":{"document_id":1},"message":"Body bodové dávky bola úspešne uložená"}
     */
    public function store(StorePointsBatchRequest $request)
    {
        [$document, $payload] = $this->service->createPointsBatch(
            $request->validated(),
            $request->user()
        );

        return $this->success([
            'document_id' => $document->id,
            'points_batch' => $payload,
        ], 'Body bodové dávky bola úspešne uložená', 201);
    }

    /**
     * Show points batch document payload.
     *
     * @group Documents
     * @urlParam document int required Document ID. Example: 1
     */
    public function show(Document $document)
    {
        // optional: ensure correct type
        if ($document->type !== 'points_batch') {
            return $this->error('Nesprávny typ dokumentu', 400);
        }

        $payload = $this->service->getPointsBatchPayload($document);
        if (!$payload) {
            return $this->error('Points batch data not found', 404);
        }

        return $this->success([
            'document' => $document,
            'points_batch' => $payload,
        ]);
    }

    /**
     * List points batch documents.
     *
     * @group Documents
     * @queryParam branch_id int optional Branch ID. Example: 2
     * @queryParam period string optional Period in YYYY-MM. Example: 2026-04
     * @queryParam per_page int optional Items per page. Example: 25
     */
    public function index(Request $request)
    {
        $branchId = $request->integer('branch_id');
        $period = $request->string('period')->toString();
        $userId = auth()->id();

        $items = Document::query()
            ->where('type', 'points_batch')
            ->with(['insuranceCompany:id,name'])
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->when($period !== '', fn($q) => $q->where('period', $period))
            ->when($userId, fn($q) => $q->where('user_id', $userId))
            ->get()
            ->map(function (Document $doc) {
                $doc->insurance_company_name = $doc->insuranceCompany?->name;

                // Load amount from payload meta
                $payload = $this->service->getPointsBatchPayload($doc);
                $doc->amount = data_get($payload, 'meta.amount', 0);

                return $doc;
            })->values();

        $items = $this->applyDocumentSort($items, $request->input('sort', '-created_at'));

        $perPage = $request->integer('per_page', 25);
        $page = max(1, $request->integer('page', 1));
        $total = $items->count();
        $lastPage = (int) ceil($total / $perPage);
        $items = $items->slice(($page - 1) * $perPage, $perPage)->values();

        return $this->success([
            'items' => $items,                 // ✅ array of rows
            'meta' => [                       // ✅ pagination info
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ]);
    }

    private function applyDocumentSort(Collection $items, ?string $sort): Collection
    {
        $sortParts = array_filter(explode(',', $sort ?: '-created_at'));

        if (empty($sortParts)) {
            return $items->values();
        }

        foreach (array_reverse($sortParts) as $part) {
            $direction = str_starts_with($part, '-') ? 'desc' : 'asc';
            $field = ltrim($part, '-');

            $items = $items->sortBy(
                fn(Document $doc) => $this->normalizeSortableValue($doc, $field),
                SORT_NATURAL | SORT_FLAG_CASE,
                $direction === 'desc'
            )->values();
        }

        return $items;
    }

    private function normalizeSortableValue(Document $doc, string $field): int|float|string
    {
        return match ($field) {
            'insurance_company_name' => mb_strtolower((string) ($doc->insurance_company_name ?? '')),
            'subtype' => mb_strtolower((string) ($doc->subtype ?? '')),
            'period' => (string) ($doc->period ?? ''),
            'name' => mb_strtolower((string) ($doc->name ?? '')),
            'updated_at' => $doc->updated_at ? strtotime((string) $doc->updated_at) ?: 0 : 0,
            'created_at' => $doc->created_at ? strtotime((string) $doc->created_at) ?: 0 : 0,
            default => $doc->{$field} ?? '',
        };
    }

    /**
     * Preview points batch document as HTML via Blade template.
     *
     * @group Documents
     * @urlParam document int required Document ID. Example: 1
     */
    public function preview(Document $document)
    {
        if ($document->type !== 'points_batch') {
            return $this->error('Nesprávny typ dokumentu', 400);
        }

        $payload = $this->service->getPointsBatchPayload($document);
        if (!$payload) {
            return $this->error('Dáta bodovej dávky sa nenašli', 404);
        }

        $sheet = app(DocumentService::class)->buildStatementSheetFromBatchPayload($payload, 'points');

        return response()->view('pdf.statement', [
            'sheet' => $sheet,
        ])->header('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Download points batch PDF generated from Blade template.
     *
     * @group Documents
     * @urlParam document int required Document ID. Example: 1
     */
    public function download(Document $document)
    {
        if ($document->type !== 'points_batch') {
            return $this->error('Nesprávny typ dokumentu', 400);
        }

        $pdfPath = app(DocumentService::class)->getTravelDocumentPdfPath($document);
        if (!$pdfPath || !Storage::disk('local')->exists($pdfPath)) {
            return $this->error('PDF bodovej dávky sa nenašlo', 500);
        }

        $downloadName = pathinfo($document->name, PATHINFO_FILENAME) . '.pdf';

        return response()->download(Storage::disk('local')->path($pdfPath), $downloadName, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * Get a signed public preview URL for points batch document.
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
