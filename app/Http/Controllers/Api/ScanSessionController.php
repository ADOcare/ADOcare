<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\Branch;
use App\Services\ScanDocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ScanSessionController extends Controller
{
    public function __construct(private ScanDocumentService $service)
    {
    }

    /**
     * Create a new scan session and return the QR code URL.
     * Called from desktop app when user clicks "Add Scanned Document"
     *
     * @group Documents
     * @bodyParam patient_id integer required Patient ID. Example: 1
     * @bodyParam branch_id integer required Branch ID. Example: 2
     * @response 201 {"session_id": 1, "session_token": "abc...", "qr_url": "https://app.url/scan/abc..."}
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'patient_id' => 'required|exists:patients,id',
            'branch_id' => 'required|exists:branches,id',
        ]);

        // Verify patient and branch exist
        $patient = Patient::findOrFail($validated['patient_id']);
        $branch = Branch::findOrFail($validated['branch_id']);

        // Create scan session
        $session = $this->service->createScanSession(
            $validated['patient_id'],
            $validated['branch_id'],
            Auth::id()
        );

        // Build the QR code URL (mobile upload page)
        $baseUrl = config('app.url');
        $qrUrl = "{$baseUrl}/scan/{$session->session_token}";

        return $this->success([
            'session_id' => $session->id,
            'session_token' => $session->session_token,
            'qr_url' => $qrUrl,
        ], 'Scan session created', 201);
    }

    /**
     * Get session status (for polling from desktop).
     *
     * @group Documents
     * @urlParam session_id int required Session ID. Example: 1
     * @response 200 {"status": "pending|completed", "document_id": 123}
     */
    public function show($sessionId)
    {
        // Validate sessionId is numeric (prevent 'undefined' string from reaching database)
        if (!is_numeric($sessionId) || $sessionId <= 0) {
            return $this->error('Invalid session ID', 400);
        }

        $session = \App\Models\ScanSession::findOrFail((int) $sessionId);
        
        // Verify user owns this session
        if ($session->user_id !== Auth::id() && !Auth::user()?->hasRole('admin')) {
            return $this->error('Unauthorized', 403);
        }

        // Check if session has expired
        if ($session->status !== 'completed' && $session->status !== 'expired' && !$session->isActive()) {
            $session->update(['status' => 'expired']);
        }

        return $this->success([
            'status' => $session->status,
            'document_id' => $session->document_id,
            'expires_in' => max(0, (int) ceil(now()->diffInSeconds($session->expires_at))),
            'lifetime_seconds' => max(1, (int) ceil($session->created_at->diffInSeconds($session->expires_at))),
        ]);
    }
}
