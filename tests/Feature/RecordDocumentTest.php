<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Patient;
use App\Models\User;
use App\Models\Branch;
use App\Models\Company;
use App\Models\NurseDiagnosis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RecordDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_creates_document_and_writes_file()
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $patient = Patient::factory()->create();

        $payload = [
            'patient_id' => $patient->id,
            'record_data' => ['notes' => 'test', 'nursingDiagnoses' => ['list' => []]],
            'form_spec' => [
                'sections' => [
                    [
                        'id' => 'notes',
                        'title' => 'Poznámky',
                        'fields' => [
                            ['id' => 'notes', 'label' => 'Poznámka', 'type' => 'textarea'],
                        ],
                    ],
                ],
            ],
        ];

        $resp = $this->actingAs($user)->postJson('/api/v1/records', $payload);
        $resp->assertStatus(201)->assertJsonPath('message', 'Ošetrovateľský záznam bol úspešne vytvorený');

        $this->assertDatabaseHas('documents', ['patient_id' => $patient->id, 'type' => 'record']);

        // there should be at least one file in records/ on the local disk
        $files = Storage::disk('local')->files('records');
        $this->assertNotEmpty($files);

        $stored = json_decode(Storage::disk('local')->get($files[0]), true);
        $this->assertSame('Poznámky', $stored['form_spec']['sections'][0]['title']);
    }

    public function test_preview_renders_all_spec_and_extra_submitted_fields()
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $patient = Patient::factory()->create();

        $document = Document::create([
            'patient_id' => $patient->id,
            'user_id' => $user->id,
            'type' => 'record',
            'mime_type' => 'application/json',
            'name' => 'zaznam',
            'path' => 'records/test.json',
        ]);

        Storage::disk('local')->put('records/test.json', json_encode([
            'document_id' => $document->id,
            'user_id' => $user->id,
            'form_spec' => [
                'sections' => [
                    [
                        'id' => 'pain',
                        'title' => 'Bolesť',
                        'fields' => [
                            [
                                'id' => 'pain.problemExists',
                                'label' => 'Bolesť – problém',
                                'type' => 'radio',
                                'options' => [
                                    ['label' => 'nie', 'value' => 'no'],
                                    ['label' => 'áno', 'value' => 'yes'],
                                ],
                            ],
                            ['id' => 'pain.location', 'label' => 'Lokalizácia', 'type' => 'text'],
                        ],
                    ],
                ],
            ],
            'form_data' => [
                'pain.problemExists' => 'yes',
                'pain.location' => 'koleno',
                'extra.selection' => ['customValue'],
            ],
        ]));

        $resp = $this->actingAs($user)->get('/api/v1/records/' . $document->id . '/preview');

        $resp->assertStatus(200);
        $resp->assertSee('Bolesť', false);
        $resp->assertSee('Bolesť – problém', false);
        $resp->assertSee('áno', false);
        $resp->assertSee('koleno', false);
        $resp->assertSee('Ďalšie vyplnené údaje', false);
        $resp->assertSee('customValue', false);
    }

    public function test_store_and_preview_render_nurse_diagnoses()
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $patient = Patient::factory()->create();
        $nurseDiagnosis = NurseDiagnosis::create([
            'code' => 'N001',
            'description' => 'Porucha mobility',
        ]);

        $payload = [
            'patient_id' => $patient->id,
            'record_data' => [
                'nursingDiagnoses.list' => [
                    [
                        'id' => $nurseDiagnosis->id,
                        'code' => $nurseDiagnosis->code,
                        'description' => $nurseDiagnosis->description,
                    ],
                ],
            ],
            'form_spec' => [
                'sections' => [
                    [
                        'id' => 'nursingDiagnoses',
                        'title' => 'Stanovenie sesterských diagnóz pri príjme',
                        'fields' => [
                            [
                                'id' => 'nursingDiagnoses.list',
                                'label' => 'Sesterské diagnózy pri príjme',
                                'type' => 'nursing-diagnoses-autocomplete',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $storeResponse = $this->actingAs($user)->postJson('/api/v1/records', $payload);
        $storeResponse->assertStatus(201);

        $documentId = $storeResponse->json('data.document_id');
        $files = Storage::disk('local')->files('records');
        $stored = json_decode(Storage::disk('local')->get($files[0]), true);

        $this->assertSame(['N001 - Porucha mobility'], $stored['form_data']['nursingDiagnoses.list']);

        $previewResponse = $this->actingAs($user)->get('/api/v1/records/' . $documentId . '/preview');

        $previewResponse->assertStatus(200);
        $previewResponse->assertSee('Stanovenie sesterských diagnóz pri príjme', false);
        $previewResponse->assertSee('Sesterské diagnózy pri príjme', false);
        $previewResponse->assertSee('N001 - Porucha mobility', false);
    }

    public function test_show_returns_record_data_for_document()
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $patient = Patient::factory()->create();

        $document = Document::create([
            'patient_id' => $patient->id,
            'user_id' => $user->id,
            'type' => 'record',
            'mime_type' => 'application/json',
            'name' => 'zaznam',
            'path' => 'records/test.json',
        ]);

        $file = ['document_id' => $document->id, 'form_data' => ['notes' => 'hello']];
        Storage::disk('local')->put('records/test.json', json_encode($file));

        $resp = $this->actingAs($user)->getJson('/api/v1/records/' . $document->id);
        $resp->assertStatus(200)->assertJsonPath('data.record_data.document_id', $document->id);
    }

    public function test_latest_by_patient_returns_latest_record()
    {
        Storage::fake('local');

        $company = Company::factory()->create(['status' => 'onboarding']);
        $branch = Branch::factory()->create(['company_id' => $company->id]);
        $user = User::factory()->create(['company_id' => $company->id]);
        $patient = Patient::factory()->create(['branch_id' => $branch->id, 'nurse_id' => $user->id]);
        $user->branches()->attach($branch->id);

        $first = Document::create([
            'patient_id' => $patient->id,
            'user_id' => $user->id,
            'type' => 'record',
            'mime_type' => 'application/json',
            'name' => 'z1',
            'path' => 'records/one.json',
        ]);
        $first->update(['created_at' => now()->subDay()]);

        $second = Document::create([
            'patient_id' => $patient->id,
            'user_id' => $user->id,
            'type' => 'record',
            'mime_type' => 'application/json',
            'name' => 'z2',
            'path' => 'records/two.json',
        ]);
        $second->update(['created_at' => now()]);

        Storage::disk('local')->put('records/one.json', json_encode(['document_id' => $first->id]));
        Storage::disk('local')->put('records/two.json', json_encode(['document_id' => $second->id]));

        $resp = $this->actingAs($user)->getJson('/api/v1/patients/' . $patient->id . '/records/latest');
        $resp->assertStatus(200)->assertJsonPath('data.document_id', $second->id);
    }
}
