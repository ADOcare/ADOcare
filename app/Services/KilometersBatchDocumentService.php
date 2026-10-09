<?php

namespace App\Services;

use App\Models\Document;
use App\Services\Transport\ClaimExportService;
use App\Services\Transport\LegacyClaimExportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class KilometersBatchDocumentService
{
    public function getKilometersBatchPayload(Document $document): ?array
    {
        // Historical documents must remain readable without the new transport migration.
        // Reads never regenerate routes, rewrite JSON or import a document into the ledger.
        if ($document->path && Storage::disk('local')->exists($document->path)) {
            $payload = json_decode(Storage::disk('local')->get($document->path), true);
            if (is_array($payload)) {
                return $payload;
            }
        }
        if (!Schema::hasTable('transport_claim_batches')) {
            return null;
        }
        $saved = DB::table('transport_claim_batches')->where('document_id', $document->id)->value('payload');
        $payload = $saved ? json_decode($saved, true) : null;
        return is_array($payload) ? $payload : null;
    }

    public function createKilometersBatch(array $data, $actor): array
    {
        return DB::transaction(function () use ($data, $actor) {
            // Serialize creation across regimes and insurers for the same worker.
            DB::table('users')->where('id', $actor->id)->lockForUpdate()->first();
            $prepared = app(ClaimExportService::class)->prepare($data, $actor);
            $context = $prepared['context'];
            $character = $context['type'];
            $scope = [
                'company_id' => $context['companyId'], 'branch_id' => $context['branchId'],
                'user_id' => $actor->id, 'insurance_company_id' => $context['insuranceId'],
                'period' => substr($context['from'], 0, 7), 'character' => $character,
            ];
            $isNew = in_array($character, ['N', 'E', 'I'], true);
            $existing = $isNew
                ? DB::table('transport_claim_batches as b')
                    ->join('documents as d', 'd.id', '=', 'b.document_id')
                    ->whereNull('d.deleted_at')
                    ->where(collect($scope)->mapWithKeys(fn ($value, $field) => ['b.' . $field => $value])->all())
                    ->select('b.*')
                    ->first()
                : null;
            if ($existing) {
                throw ValidationException::withMessages(['batchType' => [
                    'Nová dávka už bola uložená ako dokument ' . $existing->document_id . '. Stiahnite pôvodný súbor alebo zvoľte opravnú či aditívnu dávku podľa stavu podania.',
                ]]);
            }
            $revision = 1;
            $documentData = [
                'company_id' => $context['companyId'], 'branch_id' => $context['branchId'],
                'user_id' => $actor->id, 'insurance_company_id' => $context['insuranceId'],
                'period' => $scope['period'], 'subtype' => $character, 'patient_id' => null,
                'type' => 'kilometers_batch', 'mime_type' => 'application/json',
                'name' => 'kilometre_' . $character . '_davka_' . $context['batchNumber'],
                'path' => 'kilometers_batches/' . Str::uuid() . '.json',
            ];
            $document = Document::create($documentData);
            $payload = [
                'schema_version' => 2, 'document_id' => $document->id, 'revision' => $revision,
                'batchNumber' => $context['batchNumber'], 'batchType' => ['code' => $character],
                'insurance' => ['id' => $context['insuranceId']], 'period' => [$context['from'], $context['to']],
                'user' => ['id' => $actor->id], 'branch' => ['id' => $context['branchId']],
                'company' => ['id' => $context['companyId']], 'car_id' => $context['carId'],
                'patients' => array_map(fn ($id) => ['id' => $id], $context['patientIds']),
                'meta' => $prepared['sheet'] + ['totalKilometers' => $prepared['sheet']['kilometers']],
                'claim_content' => $prepared['content'], 'interface_version' => $context['interfaceVersion'],
                'lines' => $prepared['selection']['selected'], 'saved_at' => now()->toIso8601String(),
                'calculation_source' => 'TSP podľa evidovaných návštev',
                'correction_review' => in_array($character, ['O', 'F', 'J'], true) ? [
                    'confirmed_rejected' => true,
                    'reason' => trim((string) data_get($data, 'correction.reason')),
                    'reviewed_by' => $actor->id,
                    'reviewed_at' => now()->toIso8601String(),
                    'previous_line_ids' => array_values(array_filter(array_column($prepared['selection']['selected'], 'previous_line_id'))),
                ] : null,
            ];
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            if (!Storage::disk('local')->put($document->path, $json)) {
                throw new \RuntimeException('Dávku sa nepodarilo uložiť.');
            }
            $record = $scope + ['document_id' => $document->id, 'revision' => $revision, 'payload' => $json, 'updated_at' => now()];
            $batchId = DB::table('transport_claim_batches')->insertGetId($record + ['created_at' => now()]);
            foreach ($payload['lines'] as $line) {
                DB::table('transport_claim_lines')->insert([
                    'batch_id' => $batchId, 'revision' => $revision, 'journey_id' => $line['journey_id'],
                    'insurance_company_id' => $context['insuranceId'], 'patient_id' => $line['patient_id'],
                    'patient_point_id' => $line['point_id'], 'character' => $character,
                    'fingerprint' => $line['fingerprint'],
                    'payload' => json_encode($line, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            return [$document, $payload];
        });
    }

    public function downloadStored(Document $document)
    {
        abort_unless($document->type === 'kilometers_batch', 404);
        $payload = $this->getKilometersBatchPayload($document);
        abort_unless($payload, 404);
        if (($payload['schema_version'] ?? 1) < 2 && !isset($payload['claim_content'])) {
            // Use saved document scope, never the current viewer's branch, car or patient filters.
            $payload['user'] = ['id' => $document->user_id];
            $payload['branch'] = ['id' => $document->branch_id];
            $payload['company'] = ['id' => $document->company_id];
            $payload['insurance'] = ['id' => $document->insurance_company_id];
            return app(LegacyClaimExportService::class)->download($payload);
        }
        if (!is_string($payload['claim_content'] ?? null)) {
            throw ValidationException::withMessages(['document' => [
                'Uložený TXT tejto dávky chýba. Obnovte dokument zo zálohy; dávka sa nebude automaticky prepočítavať.',
            ]]);
        }
        return response($payload['claim_content'])
            ->header('Content-Type', 'text/plain; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="davka.' . $payload['batchNumber'] . '.txt"');
    }
}
