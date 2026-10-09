<?php

namespace App\Services;

use App\Http\Controllers\Api\PointsExportController;
use App\Models\Document;
use App\Models\MonthlyExportRun;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use ZipArchive;

class MonthlyExportArchiveService
{
    public function __construct(
        private DocumentService $documents,
        private PointsBatchDocumentService $pointDocuments,
        private KilometersBatchDocumentService $kilometerDocuments,
    ) {
    }

    public function download(MonthlyExportRun $run, User $actor): BinaryFileResponse
    {
        abort_unless((int) $run->user_id === (int) $actor->id, 403);
        abort_if(in_array($run->status, ['pending', 'processing'], true), 409, 'Mesačná uzávierka ešte nie je dokončená.');

        $documentIds = collect($run->results ?? [])
            ->pluck('document_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $documents = Document::query()
            ->whereIn('id', $documentIds)
            ->where('user_id', $actor->id)
            ->where('branch_id', $run->branch_id)
            ->get()
            ->keyBy('id');

        if ($documents->isEmpty()) {
            throw ValidationException::withMessages([
                'documents' => ['Pre túto uzávierku nie sú dostupné žiadne dokumenty na stiahnutie.'],
            ]);
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'monthly-export-');
        if ($temporaryPath === false) {
            throw new RuntimeException('Dočasný ZIP súbor sa nepodarilo vytvoriť.');
        }

        $zip = new ZipArchive();
        if ($zip->open($temporaryPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($temporaryPath);
            throw new RuntimeException('ZIP archív sa nepodarilo otvoriť.');
        }

        try {
            foreach ($documentIds as $documentId) {
                $document = $documents->get($documentId);
                if (!$document) {
                    continue;
                }

                $folder = $this->folder($document);
                if (in_array($document->type, ['dzc', 'cp'], true)) {
                    $pdfPath = $this->renderPdf($document);
                    $zip->addFile(
                        Storage::disk('local')->path($pdfPath),
                        $folder . '/' . $this->pdfFilename($document),
                    );
                }
                gc_collect_cycles();

                if ($document->type === 'points_batch') {
                    $payload = $this->pointDocuments->getPointsBatchPayload($document);
                    if ($payload) {
                        $zip->addFromString(
                            $folder . '/' . $this->batchFilename($document, $payload),
                            $this->pointsContent($document, $actor),
                        );
                    }
                }

                if ($document->type === 'kilometers_batch') {
                    $payload = $this->kilometerDocuments->getKilometersBatchPayload($document);
                    if (is_string($payload['claim_content'] ?? null)) {
                        $zip->addFromString(
                            $folder . '/' . $this->batchFilename($document, $payload),
                            $payload['claim_content'],
                        );
                    }
                }
            }
        } catch (\Throwable $error) {
            $zip->close();
            @unlink($temporaryPath);
            throw $error;
        }

        if (!$zip->close()) {
            @unlink($temporaryPath);
            throw new RuntimeException('ZIP archív sa nepodarilo dokončiť.');
        }

        $month = substr((string) $run->getRawOriginal('month'), 0, 7);

        return response()->download(
            $temporaryPath,
            'mesacna_uzavierka_' . $month . '.zip',
            ['Content-Type' => 'application/zip'],
        )->deleteFileAfterSend(true);
    }

    private function pointsContent(Document $document, User $actor): string
    {
        $payload = $this->documents->buildBatchDownloadPayload($document);
        if (!$payload) {
            throw new RuntimeException('Dáta výkonovej dávky sa nenašli.');
        }

        $request = Request::create('/api/v1/batches/points/download', 'POST', $payload);
        $request->setUserResolver(fn () => $actor);
        $response = app(PointsExportController::class)->download($request);

        ob_start();
        try {
            $response->sendContent();
            return (string) ob_get_clean();
        } catch (\Throwable $error) {
            ob_end_clean();
            throw $error;
        }
    }

    private function renderPdf(Document $document): string
    {
        $php = (new PhpExecutableFinder())->find(false) ?: PHP_BINARY;
        $process = new Process([
            $php,
            base_path('artisan'),
            'documents:render-pdf',
            (string) $document->id,
            '--no-ansi',
            '--quiet',
        ], base_path());
        $process->setTimeout(120);
        $process->mustRun();

        $path = $this->documents->getTravelDocumentPdfCachePath($document);
        if (!Storage::disk('local')->exists($path)) {
            throw new RuntimeException('PDF dokumentu ' . $document->id . ' sa nepodarilo vytvoriť.');
        }

        return $path;
    }

    private function folder(Document $document): string
    {
        return match ($document->type) {
            'points_batch' => 'vykonove_davky',
            'kilometers_batch' => 'dopravne_davky',
            'dzc', 'cp' => 'cestovne_dokumenty',
            default => 'ostatne',
        };
    }

    private function pdfFilename(Document $document): string
    {
        return match ($document->type) {
            'dzc' => 'denny_zaznam_ciest_' . $document->period . '.pdf',
            'cp' => 'cestovny_prikaz_' . $document->period . '.pdf',
            default => $document->name . '.pdf',
        };
    }

    private function batchFilename(Document $document, array $payload): string
    {
        return sprintf(
            '%s_poistovna_%d_davka.%s.txt',
            $document->subtype,
            $document->insurance_company_id,
            data_get($payload, 'batchNumber', $document->id),
        );
    }

}