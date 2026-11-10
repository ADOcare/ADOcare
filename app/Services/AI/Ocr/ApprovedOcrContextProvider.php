<?php

namespace App\Services\AI\Ocr;

use App\Models\Document;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Legacy class name retained for compatibility. No manual approval is required.
 * This supplies automatically derived OCR context; it is NOT clinically verified.
 * Callers must authorize access to patient records before using the context.
 */
class ApprovedOcrContextProvider
{
    public function __construct(private readonly ScanOcrSource $source)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function forPatient(int $patientId, ?CarbonInterface $before = null, int $limit = 5): array
    {
        $documents = Document::query()
            ->where('patient_id', $patientId)
            ->where('type', 'scan')
            ->when($before, fn ($query) => $query->where('created_at', '<=', $before))
            ->orderByDesc('created_at')
            ->limit(max(1, min(20, $limit)))
            ->get();

        $contexts = [];
        foreach ($documents as $document) {
            $pages = $this->source->pages($document);
            if ($pages === []) {
                continue;
            }

            $hash = $this->source->fingerprint($pages);
            $enhancement = DB::table('ai_ocr_enhancements')
                ->where('document_id', $document->id)
                ->where('source_sha256', $hash)
                ->whereIn('status', ['completed', 'approved'])
                ->orderByDesc('id')
                ->first();

            if (! $enhancement) {
                continue;
            }

            // An earlier manually reviewed result may have a corrected version.
            // New results use result_path with no separate approval workflow.
            $path = $enhancement->status === 'approved' && $enhancement->review_path
                ? $enhancement->review_path : $enhancement->result_path;
            if (! $path || ! Storage::disk('local')->exists($path)) {
                continue;
            }

            $result = json_decode(Storage::disk('local')->get($path), true);
            if (! is_array($result) || ! is_array($result['pages'] ?? null)) {
                continue;
            }

            $products = DB::table('ai_ocr_product_mentions as m')
                ->leftJoin('ai_medical_products as p', 'm.suggested_product_id', '=', 'p.id')
                ->where('m.enhancement_id', $enhancement->id)
                ->get([
                    'm.observed_text', 'm.proposed_name', 'm.source_quote',
                    'm.page_index', 'm.clinical_context', 'm.match_type',
                    'm.suggested_product_id as catalogue_product_id',
                    'p.name as catalogue_name', 'p.variant as catalogue_variant',
                    'p.active as catalogue_active',
                ]);

            $contexts[] = [
                'source_document_id' => (int) $document->id,
                'source_document_created_at' => $document->created_at?->toIso8601String(),
                'source_sha256' => $hash,
                'source_type' => 'automated_ocr_interpretation',
                'clinically_verified' => false,
                'pages' => $result['pages'],
                'product_suggestions' => $products->map(static fn ($item): array => [
                    'observed_text' => $item->observed_text,
                    'proposed_name' => $item->proposed_name,
                    'source_quote' => $item->source_quote,
                    'page_index' => (int) $item->page_index,
                    'clinical_context' => $item->clinical_context,
                    'match_type' => $item->match_type,
                    'catalogue_product_id' => $item->catalogue_active ? $item->catalogue_product_id : null,
                    'catalogue_name' => $item->catalogue_active ? $item->catalogue_name : null,
                    'catalogue_variant' => $item->catalogue_active && $item->match_type === 'exact_unique'
                        ? $item->catalogue_variant : null,
                    'clinically_verified' => false,
                ])->all(),
            ];
        }

        return $contexts;
    }
}
