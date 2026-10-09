<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Services\KilometersBatchDocumentService;
use App\Services\Transport\ClaimExportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class KilometersExportController extends Controller
{
    public function __construct(private ClaimExportService $export, private KilometersBatchDocumentService $documents)
    {
    }

    public function preview(Request $request)
    {
        $data = $this->validateInput($request);
        $candidatesOnly = (bool) ($data['candidatesOnly'] ?? false);
        $prepared = $this->export->prepare($data, $request->user(), $candidatesOnly);
        $selection = $prepared['selection'];
        if (!$candidatesOnly && !$selection['can_create']) {
            throw ValidationException::withMessages([
                'kilometers_export' => array_column($selection['blocked'], 'reason') ?: ['Nie sú dostupné jazdy na vytvorenie dávky.'],
            ]);
        }
        $candidates = array_map(function ($row) {
            unset($row['row'], $row['route_data'], $row['fingerprint'], $row['export_fields']);
            return $row;
        }, $selection['candidates']);
        return response()->json(['success' => true, 'data' => [
            'sheet' => $prepared['sheet'], 'candidates' => $candidates,
            'blocked' => $selection['blocked'], 'previewToken' => $selection['preview_token'],
            'can_create' => $selection['can_create'], 'car' => $selection['car'],
            'route_changes' => $selection['route_changes'],
            'comparison_basis' => 'Posledné uložené znenie každej jazdy v danej poisťovni a období; uloženie nepotvrdzuje prijatie poisťovňou.',
            'notice' => 'Trasa a kilometre sú vypočítané podľa evidovaných návštev. Opravná dávka slúži na reklamáciu neuznaných riadkov; zmena výkonu sama nepotvrdzuje zamietnutie poisťovňou.',
        ]]);
    }

    public function download(Request $request)
    {
        $request->headers->set('Accept', 'application/json');
        $data = $request->validate(['document_id' => ['required', 'integer']]);
        $document = Document::findOrFail($data['document_id']);
        $this->authorize('view', $document);
        return $this->documents->downloadStored($document);
    }

    public function statementPdf(Request $request)
    {
        $prepared = $this->export->prepare($this->validateInput($request), $request->user());
        return Pdf::loadView('pdf.statement', ['sheet' => $prepared['sheet']])
            ->setPaper('a4')->download('sprievodny_list_' . $prepared['sheet']['batchNumber'] . '.pdf');
    }

    private function validateInput(Request $request): array
    {
        return $request->validate([
            'batchType.code' => ['required', 'in:N,O,A,E,F,G,I,J,K'],
            'insurance.id' => ['required', 'integer', 'min:1'],
            'branch.id' => ['required', 'integer', 'min:1'],
            'period' => ['required', 'array', 'size:2'],
            'period.0' => ['required', 'date'], 'period.1' => ['required', 'date', 'after_or_equal:period.0'],
            'car_id' => ['nullable', 'integer', 'min:1'],
            'patients' => ['nullable', 'array'], 'patients.*.id' => ['required', 'integer'],
            'journeyIds' => ['sometimes', 'array'], 'journeyIds.*' => ['integer', 'min:1', 'distinct'],
            'previewToken' => ['nullable', 'string', 'size:64'],
            'candidatesOnly' => ['sometimes', 'boolean'],
            'correction' => ['sometimes', 'array'],
            'correction.confirmed' => ['sometimes', 'boolean'],
            'correction.reason' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
