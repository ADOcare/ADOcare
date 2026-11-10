<?php

namespace App\Console\Commands;

use App\Services\AI\Ocr\MedicalOcrEnhancementService;
use Illuminate\Console\Command;

class ProcessAiOcr extends Command
{
    protected $signature = 'ai:ocr:process
        {document_id : Existing scan document ID}
        {--backfill : Mark usage as internal_backfill, not customer}
        {--force : Force a new AI request even if unchanged}';

    protected $description = 'Enhance one OCR document synchronously; makes a potentially billable Vertex request.';

    public function handle(MedicalOcrEnhancementService $service): int
    {
        if (! config('ai.enabled', false)) {
            $this->error('AI_VERTEX_ENABLED is false.');
            return self::FAILURE;
        }
        try {
            $result = $service->process(
                (int) $this->argument('document_id'),
                (bool) $this->option('force'),
                (bool) $this->option('backfill')
            );
            $this->line(json_encode($result));
            return $result['status'] === 'ocr_not_ready' ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $e) {
            // Clinical OCR or Google raw response is never printed to console.
            $this->error('AI OCR processing failed: ' . get_class($e));
            return self::FAILURE;
        }
    }
}
