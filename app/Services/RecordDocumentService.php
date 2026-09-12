<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Patient;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class RecordDocumentService
{
    public function create(array $data, $user): Document
    {
        $period = !empty($data['date']) ? date('Y-m', strtotime((string) $data['date'])) : null;

        $document = Document::create([
            'patient_id' => $data['patient_id'],
            'user_id' => $user->id,
            'type' => 'record',
            'mime_type' => 'application/json',
            'name' => 'zaznam_' . now()->format('d.m.Y'),
            'path' => 'records/' . now()->timestamp . '.json',
            'period' => $period,
            'branch_id' => $data['branch_id'] ?? null,
        ]);

        $patient = Patient::findOrFail($data['patient_id']);
        $company = $user->company;
        $doctor = $patient->doctor;

        $companyName = $company ? $company->name : '';
        $companyAddress = $company
            ? trim(implode(', ', array_filter([$company->address, $company->city, $company->psc])))
            : '';
        $patientName = $patient->title . ' ' . $patient->first_name . ' ' . $patient->last_name;
        $patientBirthNumber = $patient->personal_number;
        $patientContact = $patient->contact;
        $patientAddress = $patient->address . ', ' . $patient->city . ', ' . $patient->zip;
        $insuranceCode = $patient->insuranceCompany->branch_code ?? '';
        $userName = trim((string) (($user->title ?? '') . ' ' . ($user->first_name ?? '') . ' ' . ($user->last_name ?? '')));
        $doctorName = $doctor ? trim((string) (($doctor->title ?? '') . ' ' . ($doctor->first_name ?? '') . ' ' . ($doctor->last_name ?? ''))) : '';

        $diagnosis = $this->resolveDiagnosis($data['record_data'] ?? []);
        $nurseDiagnoses = $this->resolveNurseDiagnoses($data['record_data'] ?? []);

        $processedRecordData = $data['record_data'];
        $processedRecordData['diagnosis'] = $diagnosis;
        $processedRecordData['nursingDiagnoses.list'] = $nurseDiagnoses;

        $recordData = [
            'company_address' => $companyAddress,
            'company_name' => $companyName,
            'company_id' => $company?->id,
            'user_name' => $userName,
            'user_id' => $user->id,
            'doctor_name' => $doctorName,
            'patient_name' => $patientName,
            'patient_birth_number' => $patientBirthNumber,
            'patient_address' => $patientAddress,
            'patient_contact' => $patientContact,
            'insurance_code' => $insuranceCode,
            'form_data' => $processedRecordData,
            'form_spec' => $data['form_spec'] ?? null,
            'document_id' => $document->id,
            'created_at' => now(),
        ];

        $this->storeRecordFile($document->id, $recordData);

        return $document;
    }

    protected function storeRecordFile(int $documentId, array $recordData): void
    {
        $filename = 'records/' . now()->timestamp . '.json';
        Storage::disk('local')->put($filename, json_encode($recordData, JSON_PRETTY_PRINT));
    }

    protected function resolveDiagnosis(array $recordData): array
    {
        $result = [];
        if (empty($recordData['diagnosis']) || !is_array($recordData['diagnosis'])) {
            return $result;
        }

        foreach ($recordData['diagnosis'] as $d) {
            if (!empty($d['id'])) {
                $diagnosisRecord = DB::table('diagnoses')->find((int) $d['id']);
                if ($diagnosisRecord) {
                    $result[] = $diagnosisRecord->code . ' - ' . $diagnosisRecord->description;
                }
            }
        }

        return $result;
    }

    protected function resolveNurseDiagnoses(array $recordData): array
    {
        $result = [];
        // Check for flat key first (from frontend), then nested structure
        $nurseDiagData = $recordData['nursingDiagnoses.list'] ?? $recordData['nursingDiagnoses']['list'] ?? null;
        
        if (empty($nurseDiagData) || !is_array($nurseDiagData)) {
            return $result;
        }

        foreach ($nurseDiagData as $nd) {
            if (!empty($nd['id'])) {
                $nrec = DB::table('nurse_diagnoses')->find((int) $nd['id']);
                if ($nrec) {
                    $result[] = $nrec->code . ' - ' . $nrec->description;
                }
            }
        }

        return $result;
    }

    public function findRecordFileForDocument(Document $document): ?array
    {
        $files = Storage::disk('local')->files('records');
        foreach ($files as $file) {
            $content = json_decode(Storage::disk('local')->get($file), true);
            if (($content['document_id'] ?? null) === $document->id) {
                return $content;
            }
        }

        return null;
    }
}
