<?php

namespace App\Services\AI\Ocr;

use App\Models\Document;
use Illuminate\Support\Facades\Storage;

class ScanOcrSource
{
    /** @return array<int, array{page_index: int, raw_text: string}> */
    public function pages(Document $document): array
    {
        if ($document->type !== 'scan' || ! $document->path || ! Storage::disk('local')->exists($document->path)) {
            return [];
        }

        $payload = json_decode(Storage::disk('local')->get($document->path), true);
        if (! is_array($payload)) {
            return [];
        }

        $pages = [];
        if (is_array($payload['extracted_pages'] ?? null)) {
            foreach ($payload['extracted_pages'] as $index => $page) {
                if (! is_array($page) || ! is_string($page['text'] ?? null)) {
                    continue;
                }
                $text = trim($page['text']);
                if ($text !== '') {
                    $pages[] = ['page_index' => (int) $index, 'raw_text' => $text];
                }
            }
        }

        // Some older scans have only the combined extracted_text field.
        if ($pages === [] && is_string($payload['extracted_text'] ?? null)) {
            $text = trim($payload['extracted_text']);
            if ($text !== '') {
                return [['page_index' => 0, 'raw_text' => $text]];
            }
        }

        return $pages;
    }

    /** @param array<int, array{page_index: int, raw_text: string}> $pages */
    public function fingerprint(array $pages): string
    {
        return hash('sha256', json_encode($pages, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
