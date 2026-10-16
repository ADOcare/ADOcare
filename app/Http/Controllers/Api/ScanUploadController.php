<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ScanFinalizeRequest;
use App\Http\Requests\Api\ScanSessionInfoRequest;
use App\Http\Requests\Api\ScanUploadImageRequest;
use App\Models\ScanSession;
use App\Services\ScanDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ScanUploadController extends Controller
{
    public function __construct(private ScanDocumentService $service)
    {
    }

    /**
     * Upload scanned images for a session (public, token-based).
     *
     * @group Scan Upload
     * @bodyParam session_token string required Session token. Example: abc123
    * @bodyParam reference_date date required Recommendation date in YYYY-MM-DD format. Example: 2026-10-16
     * @bodyParam images[] file required One or more image files.
     * @response 200 {"data":{"success":true,"image_paths":["scans/1.jpg"],"image_count":1},"message":"Obrazky boli uspesne nahrate."}
     */
    public function uploadImage(ScanUploadImageRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $session = $this->service->getSessionByToken($validated['session_token']);
        if (!$session) {
            return $this->error('Neplatna alebo expirovana relacia.', 401);
        }

        /** @var array<int, \Illuminate\Http\UploadedFile> $files */
        $files = $request->file('images', []);
        if (!is_array($files) || count($files) === 0) {
            Log::warning('scan upload: no files parsed', [
                'has_images_key' => $request->has('images'),
                'content_type' => $request->header('content-type'),
            ]);

            return $this->error('Neboli prijate ziadne subory (images).', 422);
        }

        try {
            $stored = $this->service->storeUploadedImagesForSession($session, $files, 'scan upload');
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success([
            'success' => true,
            'image_paths' => $stored,
            'image_count' => count($stored),
        ], 'Obrazky boli uspesne nahrate.', 200);
    }

    /**
     * Finalize the scan session and create document.
     *
     * @group Scan Upload
     * @bodyParam session_token string required Session token. Example: abc123
     * @bodyParam images[] file required One or more image files.
     * @response 200 {"data":{"document_id":1,"message":"Skenovany dokument bol uspesne vytvoreny."},"message":"Dokument bol uspesne vytvoreny."}
     */
    public function finalize(ScanFinalizeRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $session = $this->service->getSessionByToken($validated['session_token']);
        if (!$session) {
            return $this->error('Neplatna alebo expirovana relacia.', 401);
        }

        if ($session->status === 'completed') {
            return $this->error('Relacia uz bola finalizovana.', 400);
        }

        /** @var array<int, \Illuminate\Http\UploadedFile> $files */
        $files = $request->file('images', []);
        if (!is_array($files) || count($files) === 0) {
            Log::warning('scan finalize: no files parsed', [
                'has_images_key' => $request->has('images'),
                'content_type' => $request->header('content-type'),
            ]);

            return $this->error('Neboli prijate ziadne subory (images).', 422);
        }

        try {
            $filePaths = $this->service->storeUploadedImagesForSession($session, $files, 'scan finalize');
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }

        $document = $this->service->createDocumentFromScans(
            $session,
            $filePaths,
            $validated['reference_date'],
        );

        return $this->success([
            'document_id' => $document->id,
            'message' => 'Skenovany dokument bol uspesne vytvoreny.',
        ], 'Dokument bol uspesne vytvoreny.', 200);
    }

    /**
     * Get session info.
     *
     * @group Scan Upload
     * @queryParam session_token string required Session token. Example: abc123
     * @response 200 {"data":{"valid":true,"session_id":1,"patient_id":1,"patient_name":"Jan Novak","expires_in":300,"is_expired":false}}
     */
    public function getSessionInfo(ScanSessionInfoRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $session = $this->service->getSessionByToken($validated['session_token']);

        if (!$session) {
            $session = ScanSession::where('session_token', $validated['session_token'])->first();
            if ($session) {
                return response()->json([
                    'message' => 'Relacia expirovala.',
                    'data' => [
                        'debug' => [
                            'expires_at' => $session->expires_at->toIso8601String(),
                            'now' => now()->toIso8601String(),
                            'difference_seconds' => $session->expires_at->diffInSeconds(now()),
                            'is_active' => $session->isActive(),
                            'status' => $session->status,
                        ],
                    ],
                ], 401);
            }
            return $this->error('Neplatna alebo expirovana relacia.', 401);
        }

        $session->load('patient');
        $expiresInSeconds = now()->diffInSeconds($session->expires_at);

        return $this->success([
            'valid' => true,
            'session_id' => $session->id,
            'patient_id' => $session->patient_id,
            'patient_name' => trim(($session->patient->first_name ?? '') . ' ' . ($session->patient->last_name ?? '')),
            'expires_in' => max(1, $expiresInSeconds),
            'lifetime_seconds' => max(1, (int) ceil($session->created_at->diffInSeconds($session->expires_at))),
            'is_expired' => !$session->isActive(),
            'debug' => [
                'expires_at' => $session->expires_at->toIso8601String(),
                'now' => now()->toIso8601String(),
                'difference_seconds' => $expiresInSeconds,
            ],
        ]);
    }

}