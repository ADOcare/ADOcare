<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Patient;
use App\Models\User;
use App\Services\DocumentationAudit\ImmediateDocumentationAuditService;
use Illuminate\Support\Facades\Storage;

/**
 * Handles leave document creation and payload retrieval.
 */
class LeaveDocumentService
{
    public function __construct(private ImmediateDocumentationAuditService $immediateAudit)
    {
    }

    /**
     * Create a leave document and persist its JSON payload.
     */
    public function create(array $data, User $user): Document
    {
        $filePath = 'leave/' . now()->timestamp . '.json';
        $period = date('Y-m', strtotime((string) $data['date']));

        $document = Document::create([
            'patient_id' => $data['patient_id'],
            'user_id' => $user->id,
            'type' => 'leave',
            'mime_type' => 'application/json',
            'name' => 'prepustacia_sprava_' . now()->format('d.m.Y'),
            'path' => $filePath,
            'period' => $period,
            'branch_id' => $data['branch_id'] ?? null,
        ]);

        $patient = Patient::findOrFail((int) $data['patient_id']);
        $payload = $this->buildPayload($data, $document, $patient, $user);

        Storage::disk('local')->put(
            $filePath,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        $this->immediateAudit->documentsChanged([$document]);

        return $document;
    }

    /**
     * Build a leave document payload without persisting a document.
     */
    public function preview(array $data, User $user): array
    {
        $patient = Patient::findOrFail((int) $data['patient_id']);

        return $this->buildPayload($data, null, $patient, $user);
    }

    /**
     * Find the latest leave document for a patient.
     */
    public function findLatestDocumentByPatientId(int $patientId): ?Document
    {
        return Document::where('patient_id', $patientId)
            ->where('type', 'leave')
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();
    }

    /**
     * Resolve leave payload for a document.
     */
    public function findLeaveFileForDocument(Document $document): ?array
    {
        if ($document->path && Storage::disk('local')->exists($document->path)) {
            $payload = $this->decodePayload(Storage::disk('local')->get($document->path));
            if (($payload['document_id'] ?? null) === $document->id) {
                return $payload;
            }
        }

        $files = Storage::disk('local')->files('leave');
        foreach ($files as $file) {
            $payload = $this->decodePayload(Storage::disk('local')->get($file));
            if (($payload['document_id'] ?? null) === $document->id) {
                return $payload;
            }
        }

        return null;
    }

    /**
     * Build payload saved into the leave JSON file.
     */
    private function buildPayload(array $data, ?Document $document, Patient $patient, User $user): array
    {
        return [
            'user_name' => $this->fullName($user->title, $user->first_name, $user->last_name),
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'patient_name' => $this->fullName($patient->title, $patient->first_name, $patient->last_name),
            'patient_birth_number' => $patient->personal_number,
            'date' => $data['date'],
            'problems' => $data['problems'] ?? [],
            'other_findings' => $data['other_findings'] ?? '',
            'results' => $data['results'] ?? '',
            'education' => $data['education'] ?? '',
            'received' => $data['received'] ?? '',
            'document_id' => $document?->id,
            'created_at' => now(),
        ];
    }

    /**
     * Safely decode a JSON payload to array.
     */
    private function decodePayload(string $content): array
    {
        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Build full name from optional name segments.
     */
    private function fullName(?string $title, ?string $firstName, ?string $lastName): string
    {
        return trim(implode(' ', array_filter([$title, $firstName, $lastName])));
    }
}
