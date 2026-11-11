<?php

namespace App\Console\Commands;

use App\Jobs\EnhanceScanOcr;
use App\Models\Document;
use App\Services\AI\Ocr\ScanOcrSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillAiOcr extends Command
{
    protected $signature = 'ai:ocr:backfill
        {--limit=20 : Max documents examined, up to 500}
        {--after-id=0 : Resume after this document ID}
        {--company= : Restrict to a company ID}
        {--run : Queue enhancements (otherwise preview only)}
        {--force : Allow reprocessing even if a matching result exists}';

    protected $description = 'Preview or queue historical OCR enhancements. Backfill API costs are marked internal.';

    public function handle(ScanOcrSource $source): int
    {
        if ($this->option('run') && ! config('ai.enabled', false)) {
            $this->error('AI_VERTEX_ENABLED must be true to queue enhancements.');
            return self::FAILURE;
        }
        $limit = max(1, min(500, (int) $this->option('limit')));
        $afterId = max(0, (int) $this->option('after-id'));
        $company = $this->option('company');
        if ($company !== null && (! ctype_digit((string) $company) || (int) $company < 1)) {
            $this->error('Company ID must be a positive integer.');
            return self::FAILURE;
        }
        $documents = Document::query()->where('type', 'scan')->where('id', '>', $afterId)
            ->when($company !== null, function ($query) use ($company): void {
                $query->where(function ($q) use ($company): void {
                    $q->where('company_id', (int) $company)
                        ->orWhereHas('branch', fn ($b) => $b->where('company_id', (int) $company));
                });
            })
            ->orderBy('id')->limit($limit)->get();
        $rows = [];
        $queued = 0;
        foreach ($documents as $document) {
            $pages = $source->pages($document);
            $status = 'no_ocr';
            if ($pages !== []) {
                $hash = $source->fingerprint($pages);
                $done = DB::table('ai_ocr_enhancements')
                    ->where('document_id', $document->id)
                    ->where('source_sha256', $hash)
                    ->where('pipeline_version', (string) config('ai_ocr.pipeline_version', 'ocr-v1'))
                    ->whereIn('status', ['completed', 'approved'])
                    ->exists();
                $status = $done && ! $this->option('force') ? 'already_done' : 'candidate';
                if ($status === 'candidate' && $this->option('run')) {
                    EnhanceScanOcr::dispatch((int) $document->id, true, (bool) $this->option('force'));
                    $queued++;
                    $status = 'queued';
                }
            }
            $rows[] = [(int) $document->id, count($pages), $status];
        }
        $this->table(['Document ID', 'OCR pages', 'Status'], $rows);
        $this->line('Examined ' . count($rows) . ', queued ' . $queued . '. Highest ID: ' . ($documents->last()?->id ?? $afterId));
        if (! $this->option('run')) {
            $this->warn('Dry-run preview only. Add --run to dispatch paid API work.');
        }
        return self::SUCCESS;
    }
}
