<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Services\AI\Ocr\ScanOcrSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Read-only inspection endpoint. There is no approval requirement or review action. */
class ScanAiOcrController extends Controller
{
    public function show(Document $document, ScanOcrSource $source)
    {
        $this->authorize('view', $document);
        abort_unless($document->type === 'scan', 404);

        $enhancement = DB::table('ai_ocr_enhancements')
            ->where('document_id', $document->id)->orderByDesc('id')->first();
        if (! $enhancement) {
            return $this->success(['status' => 'not_processed', 'enhancement' => null]);
        }

        $result = $enhancement->result_path && Storage::disk('local')->exists($enhancement->result_path)
            ? json_decode(Storage::disk('local')->get($enhancement->result_path), true) : null;
        $mentions = DB::table('ai_ocr_product_mentions as m')
            ->leftJoin('ai_medical_products as p', 'm.suggested_product_id', '=', 'p.id')
            ->where('m.enhancement_id', $enhancement->id)
            ->orderBy('m.page_index')->orderBy('m.id')
            ->get([
                'm.page_index', 'm.observed_text', 'm.proposed_name', 'm.source_quote',
                'm.clinical_context', 'm.suggested_product_id', 'm.match_type',
                'p.name as suggested_product_name', 'p.variant as suggested_product_variant',
            ]);

        return $this->success([
            'status' => $enhancement->status,
            'enhancement_id' => $enhancement->id,
            'source_changed' => $result !== null && $source->fingerprint($source->pages($document))
                !== $enhancement->source_sha256,
            'result' => $result,
            'mentions' => $mentions,
            'requires_nurse_confirmation' => false,
            'clinically_verified' => false,
        ]);
    }
}
