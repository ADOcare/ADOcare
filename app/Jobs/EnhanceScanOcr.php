<?php

namespace App\Jobs;

use App\Services\AI\Ocr\MedicalOcrEnhancementService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class EnhanceScanOcr implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public array $backoff = [45];

    public function __construct(
        public readonly int $documentId,
        public readonly bool $backfill = false,
        public readonly bool $force = false
    ) {
    }

    public function handle(MedicalOcrEnhancementService $service): void
    {
        if (! config('ai.enabled', false)) {
            return;
        }
        $result = $service->process($this->documentId, $this->force, $this->backfill);
        if ($result['status'] === 'ocr_not_ready' && $this->attempts() < $this->tries) {
            $this->release(30);
            return;
        }
        Log::info('AI OCR enhancement completed', [
            'document_id' => $this->documentId,
            'status' => $result['status'],
            'enhancement_id' => $result['enhancement_id'],
        ]);
    }
}
