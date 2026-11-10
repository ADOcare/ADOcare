<?php

namespace App\Jobs;

use App\Models\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class ProcessScanOcr implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [60, 120, 300]; // 1 min, 2 min, 5 min

    public function __construct(
        private Document $document,
    ) {
    }

    public function handle(): void
    {
        if ($this->document->type !== 'scan' || !$this->document->path) {
            Log::warning('ProcessScanOcr: invalid document', ['id' => $this->document->id]);
            return;
        }

        // Relative path from storage root (e.g., "scans/documents/138_1234567.json")
        $jsonPath = $this->document->path;

        try {
            $ocrUrl = config('services.ocr.url') ?? 'http://127.0.0.1:8081';
            $endpoint = $ocrUrl . '/ocr/json';

            Log::info('ProcessScanOcr: calling OCR', [
                'document_id' => $this->document->id,
                'endpoint' => $endpoint,
                'json_path' => $jsonPath,
            ]);

            $response = Http::timeout(300) // 5 minutes
                ->retry(2, 1000)
                ->post($endpoint, [
                    'json_path' => $jsonPath,
                ]);

            if (!$response->successful()) {
                Log::error('ProcessScanOcr: OCR failed', [
                    'document_id' => $this->document->id,
                    'status' => $response->status(),
                ]);
                throw new \Exception("OCR returned status {$response->status()}");
            }

            // Never log OCR response bodies; they may contain personal health data.
            Log::info('ProcessScanOcr: success', [
                'document_id' => $this->document->id,
                'status' => $response->status(),
            ]);

            if (config('ai.enabled', false) && config('ai_ocr.auto_enhance', false)) {
                EnhanceScanOcr::dispatch((int) $this->document->id);
            }
        } catch (\Throwable $e) {
            Log::error('ProcessScanOcr: exception', [
                'document_id' => $this->document->id,
                'exception_class' => get_class($e),
            ]);

            // Rethrow so queue knows to retry
            throw $e;
        }
    }
}
