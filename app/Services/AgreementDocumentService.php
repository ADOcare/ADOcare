<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Patient;
use App\Models\Branch;
use App\Services\DocumentationAudit\ImmediateDocumentationAuditService;
use Illuminate\Support\Facades\Storage;

/**
 * Service responsible for creating and reading agreement document payloads.
 */
class AgreementDocumentService
{
    public function __construct(private ImmediateDocumentationAuditService $immediateAudit)
    {
    }

    /**
     * Create document DB record and persist agreement JSON to storage.
     * Returns the created Document and the stored agreement payload.
     *
     * @param  array  $data  validated input (date, patient_id, branch_id)
     * @param  \App\Models\User  $actor
     */
    public function createAgreement(array $data, $actor): array
    {
        $patient = Patient::findOrFail($data['patient_id']);
        $branch = Branch::findOrFail($data['branch_id']);

        $document = Document::create([
            'patient_id' => $patient->id,
            'user_id' => $actor->id,
            'type' => 'agreement',
            'mime_type' => 'application/json',
            'name' => 'dohoda_' . now()->format('d.m.Y'),
            'path' => 'agreements/' . now()->timestamp . '.json',
            'branch_id' => $branch->id,
            'period' => date('Y-m', strtotime($data['date'])),
        ]);

        $agreementData = $this->buildAgreementData($data, $patient, $branch, $document->id);

        Storage::disk('local')->put($document->path, json_encode($agreementData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->immediateAudit->documentsChanged([$document]);

        return [$document, $agreementData];
    }

    /** Build an agreement payload without persisting a document. */
    public function previewAgreement(array $data): array
    {
        return $this->buildAgreementData(
            $data,
            Patient::findOrFail($data['patient_id']),
            Branch::findOrFail($data['branch_id']),
        );
    }

    private function buildAgreementData(array $data, Patient $patient, Branch $branch, ?int $documentId = null): array
    {
        $representative = $branch->representative;

        return [
            'company_id' => $branch->company?->id,
            'branch_id' => $branch->id,
            'branch_representative_id' => $representative?->id,
            'company_address' => $branch->company?->address ?? '',
            'company_name' => $branch->company?->name ?? '',
            'company_city' => $branch->company?->city ?? '',
            'company_stamp_path' => $branch->company?->stamp_path,
            'branch_city' => $branch->city ?? '',
            'user_name' => trim(($representative?->title ?? '') . ' ' . ($representative?->first_name ?? '') . ' ' . ($representative?->last_name ?? '')),
            'user_contact' => $representative?->phone_number ?? '',
            'representative_signature_path' => $representative?->signature_path,
            'patient_name' => trim(($patient->first_name ?? '') . ' ' . ($patient->last_name ?? '') . ' ' . ($patient->title ?? '')),
            'patient_birth_number' => $patient->personal_number ?? '',
            'patient_address' => trim(($patient->address ?? '') . ', ' . ($patient->city ?? '') . ', ' . ($patient->postal_code ?? '')),
            'date' => $data['date'],
            'document_id' => $documentId,
            'created_at' => now(),
        ];
    }

    /**
     * Read the agreement JSON payload for a document (by path).
     */
    public function getAgreementPayload(Document $document): ?array
    {
        if (! $document->path || ! Storage::disk('local')->exists($document->path)) {
            return null;
        }

        $content = Storage::disk('local')->get($document->path);
        return json_decode($content, true);
    }
}
