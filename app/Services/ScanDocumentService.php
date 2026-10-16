<?php

namespace App\Services;

use App\Jobs\ProcessScanOcr;
use App\Models\Document;
use App\Models\ScanSession;
use App\Models\Patient;
use App\Services\DocumentationAudit\ImmediateDocumentationAuditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Service responsible for creating scan documents, managing scan sessions,
 * and handling scanned image uploads.
 */
class ScanDocumentService
{
    public function __construct(
        private ScanImageNormalizerService $imageNormalizer,
        private ImmediateDocumentationAuditService $immediateAudit,
    ) {
    }

    /**
     * Create a new scan session for a patient.
     * The session is used to link mobile uploads to a specific patient/branch context.
     *
     * @param int $patientId
     * @param int $branchId
     * @param int $userId
     * @return ScanSession
     */
    public function createScanSession(int $patientId, int $branchId, int $userId): ScanSession
    {
        $sessionToken = Str::random(32);
        
        return ScanSession::create([
            'patient_id' => $patientId,
            'branch_id' => $branchId,
            'user_id' => $userId,
            'session_token' => $sessionToken,
            'expires_at' => now()->addMinutes(5),
            'status' => 'pending',
        ]);
    }

    /**
     * Get a session by token.
     *
     * @param string $token
     * @return ScanSession|null
     */
    public function getSessionByToken(string $token): ?ScanSession
    {
        return ScanSession::where('session_token', $token)
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * Store a scanned image for a session.
     *
     * @param ScanSession $session
     * @param $file The uploaded image file
     * @return string The path where the file was stored
     */
    public function storeScannedImage(ScanSession $session, $file): string
    {
        $filename = 'nalez_' . $session->id . '_' . now()->timestamp . '_' . Str::random(8) . '.' . $file->getClientOriginalExtension();
        $path = 'scans/' . $session->id . '/' . $filename;
        
        Storage::disk('local')->putFileAs('scans/' . $session->id, $file, $filename);
        
        return $path;
    }

    /**
     * Store a base64 encoded image for a session.
     *
     * @param ScanSession $session
     * @param string $base64Image Base64 encoded image data (data:image/jpeg;base64,...)
     * @return string The path where the file was stored
     */
    public function storeBase64Image(ScanSession $session, string $base64Image): string
    {
        // Extract the base64 data and mime type
        if (preg_match('/^data:image\/(\w+);base64,/', $base64Image, $matches)) {
            $extension = $matches[1];
            $base64Data = preg_replace('/^data:image\/\w+;base64,/', '', $base64Image);
        } else {
            // If no mime type is specified, default to jpg
            $extension = 'jpg';
            $base64Data = $base64Image;
        }

        $binaryData = base64_decode($base64Data, true);
        if ($binaryData === false) {
            throw new \Exception('Invalid base64 image data');
        }

        $filename = 'nalez_' . $session->id . '_' . now()->timestamp . '_' . Str::random(8) . '.' . $extension;
        $path = 'scans/' . $session->id . '/' . $filename;
        
        Storage::disk('local')->put($path, $binaryData);
        
        return $path;
    }

    /**
     * Create a document record from scanned images.
     * This is called after uploads are complete.
     *
     * @param ScanSession $session
     * @param array $imagePaths Array of storage paths to images
     * @param string $referenceDate Recommendation date in YYYY-MM-DD format
     * @return Document
     */
    public function createDocumentFromScans(
        ScanSession $session,
        array $imagePaths,
        string $referenceDate,
    ): Document
    {
        $patient = Patient::findOrFail($session->patient_id);
        
        $document = Document::create([
            'patient_id' => $session->patient_id,
            'user_id' => $session->user_id,
            'type' => 'scan',
            'mime_type' => 'application/json',
            'name' => 'nalez_' . now()->format('d.m.Y'),
            'path' => 'scans/documents/' . $session->id . '_' . now()->timestamp . '.json',
            'branch_id' => $session->branch_id,
            'period' => substr($referenceDate, 0, 7),
            'reference_date' => $referenceDate,
        ]);

        // Store metadata about the scan document
        $scanData = [
            'document_id' => $document->id,
            'scan_session_id' => $session->id,
            'image_paths' => $imagePaths,
            'image_count' => count($imagePaths),
            'reference_date' => $referenceDate,
            'scanned_at' => now(),
            'uploaded_by' => $session->user_id,
            'patient_name' => trim(($patient->first_name ?? '') . ' ' . ($patient->last_name ?? '')),
            'patient_birth_number' => $patient->personal_number ?? '',
        ];

        Storage::disk('local')->put($document->path, json_encode($scanData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // Update session status
        $session->update([
            'status' => 'completed',
            'document_id' => $document->id,
        ]);

        // Dispatch OCR job (async processing)
        ProcessScanOcr::dispatch($document)->delay(now()->addSeconds(2));

        $this->immediateAudit->documentsChanged([$document]);

        return $document;
    }

    /**
     * Get scan document payload (metadata).
     *
     * @param Document $document
     * @return array|null
     */
    public function getScanPayload(Document $document): ?array
    {
        if (!$document->path || !Storage::disk('local')->exists($document->path)) {
            return null;
        }

        $content = Storage::disk('local')->get($document->path);
        return json_decode($content, true);
    }

    /**
     * Get image paths for a scan document.
     *
     * @param Document $document
     * @return array
     */
    public function getScanImages(Document $document): array
    {
        $payload = $this->getScanPayload($document);
        return $payload['image_paths'] ?? [];
    }

    /**
     * Validate, normalize and store uploaded scan images for a session.
     *
     * @param UploadedFile[] $files
     * @return array<int, string>
     */
    public function storeUploadedImagesForSession(ScanSession $session, array $files, string $logPrefix): array
    {
        $storedPaths = [];

        foreach ($files as $i => $file) {
            if (!$file instanceof UploadedFile) {
                Log::warning($logPrefix . ": images.$i is null");
                continue;
            }

            if ($file->getError() !== UPLOAD_ERR_OK) {
                Log::warning($logPrefix . ": images.$i upload error", [
                    'error' => $file->getError(),
                    'error_message' => $file->getErrorMessage(),
                    'original' => $file->getClientOriginalName(),
                    'client_mime' => $file->getClientMimeType(),
                    'size' => $file->getSize(),
                ]);

                throw new RuntimeException("Nahravanie zlyhalo pre images.$i: " . $file->getErrorMessage());
            }

            $clientMime = $file->getClientMimeType() ?: '';
            if (!$this->imageNormalizer->isAllowedMime($clientMime)) {
                throw new RuntimeException("Nepodporovany typ obrazka pre images.$i ({$clientMime})");
            }

            $normalizedFile = $this->imageNormalizer->normalizeToJpegIfNeeded($file);
            $storedPaths[] = $this->storeScannedImage($session, $normalizedFile);
        }

        return $storedPaths;
    }
}
