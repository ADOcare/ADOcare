<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\DocumentService;
use Illuminate\Console\Command;

class RenderDocumentPdf extends Command
{
    protected $signature = 'documents:render-pdf {document}';

    protected $description = 'Render one document PDF into the local cache';

    public function handle(DocumentService $documents): int
    {
        $document = Document::findOrFail((int) $this->argument('document'));
        $path = $documents->getTravelDocumentPdfPath($document);

        return $path ? self::SUCCESS : self::FAILURE;
    }
}