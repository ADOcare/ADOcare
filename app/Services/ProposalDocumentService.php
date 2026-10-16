<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Patient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Models\Branch;
use App\Services\DocumentationAudit\ImmediateDocumentationAuditService;

class ProposalDocumentService
{
    public function __construct(private ImmediateDocumentationAuditService $immediateAudit)
    {
    }

    /**
     * Create proposal document and persist JSON payload.
     * @return array [Document, array(payload)]
     */
    public function createProposal(array $data, $actor): array
    {
        $patient = Patient::findOrFail($data['patient_id']);
        $branch = Branch::findOrFail($data['branch_id']);

        $document = Document::create([
            'patient_id' => $patient->id,
            'user_id' => $actor->id,
            'type' => 'proposal',
            'mime_type' => 'application/json',
            'name' => 'navrh_' . now()->format('d.m.Y'),
            'path' => 'proposals/' . now()->timestamp . '.json', 
            'period' => date('Y-m', strtotime($data['date'])),
            'branch_id' => $data['branch_id'],
            ]);

        $payload = $this->buildProposalData($data, $actor, $patient, $branch, $document->id);

        Storage::disk('local')->put($document->path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->immediateAudit->documentsChanged([$document]);

        return [$document, $payload];
    }

    public function previewProposal(array $data, $actor): array
    {
        return $this->buildProposalData(
            $data,
            $actor,
            Patient::findOrFail($data['patient_id']),
            Branch::findOrFail($data['branch_id']),
        );
    }

    private function buildProposalData(array $data, $actor, Patient $patient, Branch $branch, ?int $documentId = null): array
    {
        $company = $actor->company;
        $doctor = $patient->doctor;

        $diagnosis = [];
        if (!empty($data['medical_diagnosis_ids']) && is_array($data['medical_diagnosis_ids'])) {
            foreach ($data['medical_diagnosis_ids'] as $diagId) {
                $d = DB::table('diagnoses')->find((int) $diagId);
                if ($d) {
                    $diagnosis[] = $d->code . ' - ' . $d->description;
                }
            }
        }

        $nurseDiagnosis = [];
        if (!empty($data['nurse_diagnosis_ids']) && is_array($data['nurse_diagnosis_ids'])) {
            foreach ($data['nurse_diagnosis_ids'] as $nurseDiagId) {
                $nd = DB::table('nurse_diagnoses')->find((int) $nurseDiagId);
                if ($nd) {
                    $nurseDiagnosis[] = $nd->code . ' - ' . $nd->description;
                }
            }
        }

        $procedures = [];
        if (!empty($data['procedures']) && is_array($data['procedures'])) {
            foreach ($data['procedures'] as $procEntry) {
                if (empty($procEntry['procedure_id'])) continue;
                $proc = DB::table('procedures')->find((int) $procEntry['procedure_id']);
                if (! $proc) continue;
                $procedures[] = [
                    'code' => $proc->code,
                    'frequency' => $procEntry['frequency'] ?? '',
                ];
            }
        }

        return [
            'company_id' => $company?->id,
            'representative_id' => $branch->representative?->id ?? null,
            'representative_name' => trim(($branch->representative?->title ?? '') . ' ' . ($branch->representative?->first_name ?? '') . ' ' . ($branch->representative?->last_name ?? '')),
            'company_address' => $company?->address ?? '',
            'company_name' => $company?->name ?? '',
            'user_name' => trim(' ' . ($actor->title ?? '')) . ' ' . ($actor->first_name ?? '') . ' ' . ($actor->last_name ?? ''),
            'doctor_name' => trim(($doctor->title ?? '') . ' ' . ($doctor->first_name ?? '') . ' ' . ($doctor->last_name ?? '')),
            'patient_name' => trim(($patient->first_name ?? '') . ' ' . ($patient->last_name ?? '') . ' ' . ($patient->title ?? '')),
            'patient_birth_number' => $patient->personal_number ?? '',
            'patient_address' => trim(($patient->address ?? '') . ', ' . ($patient->city ?? '') . ', ' . ($patient->postal_code ?? '')),
            'insurance_code' => $patient->insuranceCompany->branch_code ?? '',
            'diagnosis' => $diagnosis,
            'nurse_diagnosis' => $nurseDiagnosis,
            'epicrisis' => $data['epicrisis_description'] ?? '',
            'care_plan' => $data['care_plan'] ?? '',
            'mobility' => $data['patient_mobility'] ?? [],
            'expected_duration' => $data['expected_duration'] ?? '',
            'date' => $data['date'] ?? null,
            'procedures' => $procedures,
            'document_id' => $documentId,
            'created_at' => now(),
        ];
    }

    public function getProposalPayload(Document $document): ?array
    {
        if (! $document->path || ! Storage::disk('local')->exists($document->path)) return null;
        $content = Storage::disk('local')->get($document->path);
        return json_decode($content, true);
    }
}
