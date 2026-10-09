<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Document;
use App\Models\Patient;
use App\Services\DZCDocumentService;
use App\Services\DocumentService;
use App\Services\Transport\DzcExportService;
use App\Http\Requests\StoreDZCRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class DZCDocumentController extends Controller
{
    public function __construct(private DZCDocumentService $service)
    {
    }

    public function exportOptions(Document $document, DzcExportService $export)
    {
        $this->authorize('view', $document);
        return $this->success($export->options($document));
    }

    public function exportXlsx(Request $request, Document $document, DzcExportService $export)
    {
        $this->authorize('update', $document);
        $data = $request->validate([
            'end_km' => ['required', 'numeric', 'min:0', 'max:999999999', 'decimal:0,3'],
            'route_fingerprint' => ['required', 'string', 'size:64'],
        ]);
        $path = $export->export($document, $request->user(), (float) $data['end_km'], $data['route_fingerprint']);
        return response()->download($path, 'kniha_jazd_' . $document->id . '.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store',
        ])->deleteFileAfterSend(true);
    }

    public function cars(Request $request)
    {
        $data = $request->validate(['branch_id' => ['required', 'integer', 'exists:branches,id']]);
        $branch = Branch::findOrFail($data['branch_id']);
        abort_unless($request->user()->isInBranch((int) $branch->id), 403);
        return $this->success(\App\Models\Car::query()->where('company_id', $branch->company_id)
            ->where('user_id', $request->user()->id)->orderBy('evc')->get(['id', 'evc', 'model', 'vin']));
    }

    /**
     * List DZC documents for the current user.
     *
     * @group Documents
     * @queryParam branch_id int optional Filter by branch id.
     * @response 200 {"data": [{"id":1, "name":"dzc_..."}]}
     */
    public function index(Request $request)
    {
        $query = Document::where('type', 'dzc')
            ->where('user_id', Auth::id())
            ->orderBy('created_at', 'desc');

        $documents = $query->get()->map(fn($doc) => [
            'id' => $doc->id,
            'name' => $doc->name,
            'type' => $doc->type,
            'mime_type' => $doc->mime_type,
            'created_at' => $doc->created_at,
            'path' => $doc->path,
        ]);

        return $this->success(['data' => $documents]);
    }

    /**
     * Create a DZC (Denný záznam ciest) document for the current user.
     *
     * @group Documents
     * @bodyParam start date required Start date. Example: 2024-01-01
     * @bodyParam end date required End date. Example: 2024-01-31
     * @bodyParam branch_id integer required Branch ID. Example: 2
     * @response 201 {"document_id":123, "dzc": {...}}
     */
    public function store(StoreDZCRequest $request)
    {
        [$document, $payload] = $this->service->createDzc($request->validated(), $request->user());

        return $this->success(['document_id' => $document->id, 'dzc' => $payload], 'Denný záznam ciest bol úspešne vytvorený', 201);
    }

    /**
     * Show DZC document payload.
     *
     * @group Documents
     * @urlParam document int required Document ID. Example: 123
     * @response 200 {"document": {...}, "dzc_data": {...}}
     */
    public function show(Document $document)
    {
        $document->loadMissing('user');

        $payload = $this->service->getDzcPayload($document);
        if (! $payload) {
            return $this->error('Denný záznam ciest data not found', 404);
        }

        return $this->success(['document' => $document, 'dzc_data' => $payload]);
    }

    /**
     * Export DZC as CSV.
     *
     * @group Documents
     * @urlParam document int required Document ID. Example: 123
     * @responsefile 200 dzc.csv
     */
    public function exportCsv(Document $document)
    {
        $payload = $this->service->getDzcPayload($document);
        if (! $payload) {
            return $this->error('Denný záznam ciest data not found', 404);
        }

        $csv = $this->generateDZCCsv($payload);
        $filename = 'dzc' . ($payload['month'] ?? '') . '_' . ($payload['year'] ?? '') . '.csv';

        return response($csv)
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }

    /**
     * Preview DZC document as HTML via Blade template.
     *
     * @group Documents
     */
    public function preview(Document $document)
    {
        $document->loadMissing('user');

        $payload = $this->service->getDzcPayload($document);
        if (! $payload) {
            return $this->error('Denný záznam ciest data not found', 404);
        }

        $signatureDataUri = app(DocumentService::class)->getUserSignatureDataUri((int) ($payload['user_id'] ?? 0));

        return response()->view('pdf.travel_dzc', [
            'dzcData' => $payload,
            'signatureDataUri' => $signatureDataUri,
        ])->header('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Get a signed public preview URL for DZC document.
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

    /**
     * Download DZC PDF generated from Blade template.
     *
     * @group Documents
     */
    public function download(Document $document)
    {
        $document->loadMissing('user');

        $pdfPath = app(DocumentService::class)->getTravelDocumentPdfPath($document);
        if (! $pdfPath || ! Storage::disk('local')->exists($pdfPath)) {
            return $this->error('Denný záznam ciest PDF not found', 500);
        }

        $downloadName = pathinfo($document->name, PATHINFO_FILENAME) . '.pdf';

        return response()->download(Storage::disk('local')->path($pdfPath), $downloadName, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    private function generateDZCCsv(array $dzcFile): string
    {
        $output = fopen('php://memory', 'w');

        // Header info
        fputcsv($output, ['DENNÝ ZÁZNAM CIEST']);
        fputcsv($output, []);
        fputcsv($output, ['Pracovník', $dzcFile['user_name'] ?? '']);
        fputcsv($output, ['Obdobie', ($dzcFile['month'] ?? '') . '/' . ($dzcFile['year'] ?? '')]);
        fputcsv($output, ['Vozidlo', ($dzcFile['car_model'] ?? '') . ' (' . ($dzcFile['car_license_plate'] ?? '') . ')']);
        fputcsv($output, ['Prevádzka', $dzcFile['branch_address'] ?? '']);
        fputcsv($output, []);

        // Daily records
        fputcsv($output, ['Dátum', 'Počet km', 'Trvanie','Poradové číslo', 'Príchod', 'Adresa']);

        $patientAddresses = $dzcFile['patient_addresses'] ?? [];
        $dayTotals = $dzcFile['day_totals'] ?? [];

        foreach ($patientAddresses as $date => $addresses) {
            $dayTotal = $dayTotals[$date] ?? null;

            foreach ($addresses as $idx => $addr) {
                $row = [];

                if ($idx === 0) {
                    $row[] = $date;
                    $row[] = $dayTotal['distance_km'] ?? '';
                    $row[] = $dayTotal['total_time'] ?? '';

                } else {
                    $row[] = '';
                    $row[] = '';
                    $row[] = '';
                }

                // Address info
                $row[] = $idx + 1;
                $row[] = $addr['arrival_time'] ?? '';
                $row[] = $addr['address'] ?? '';

                fputcsv($output, $row);
            }
        }

        fputcsv($output, []);

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }
}
