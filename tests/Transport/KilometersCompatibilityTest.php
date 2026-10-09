<?php

namespace Tests\Transport;

use App\Http\Requests\StoreKilometersBatchRequest;
use App\Models\Branch;
use App\Models\Document;
use App\Models\User;
use App\Services\DocumentService;
use App\Services\KilometersBatchDocumentService;
use App\Services\Transport\LegacyClaimExportService;
use App\Services\Transport\RoutePlanner;
use App\Services\Transport\RouteService;
use App\Services\Transport\StopGrouper;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class KilometersCompatibilityTest extends TransportTestCase
{
    private function storedFiles(array $files): void
    {
        // Deliberately no put/delete API: a historical read must never write its source.
        Storage::swap(new class($files) {
            public function __construct(private array $files)
            {
            }

            public function disk(string $name): self
            {
                return $this;
            }

            public function exists(string $path): bool
            {
                return array_key_exists($path, $this->files);
            }

            public function get(string $path): string
            {
                return $this->files[$path];
            }
        });
    }

    public function test_historical_payload_is_read_unchanged_without_transport_tables_or_export_services(): void
    {
        $payload = ['document_id' => 7, 'period' => ['2026-03-01', '2026-03-31'],
            'meta' => ['amount' => '12.50', 'totalKilometers' => 25], 'patients' => [['id' => 15]]];
        $this->storedFiles(['old.json' => json_encode($payload)]);
        $document = (new Document())->forceFill(['id' => 7, 'type' => 'kilometers_batch', 'path' => 'old.json']);
        // The container has no database or generator bound; either dependency would fail.
        self::assertSame($payload, (new KilometersBatchDocumentService())->getKilometersBatchPayload($document));
    }

    public function test_invalid_or_missing_old_file_does_not_break_history_listing_without_new_table(): void
    {
        $this->database();
        Schema::drop('transport_claim_batches');
        $this->storedFiles(['bad.json' => '{broken']);
        foreach (['bad.json', 'missing.json'] as $path) {
            $document = (new Document())->forceFill(['id' => 7, 'path' => $path]);
            self::assertNull((new KilometersBatchDocumentService())->getKilometersBatchPayload($document));
        }
    }

    public function test_bulk_deletion_soft_deletes_document_before_removing_asset(): void
    {
        $this->database();
        Schema::table('documents', function ($table) {
            $table->string('path')->nullable();
            $table->timestamps();
        });
        $documentId = DB::table('documents')->insertGetId([
            'company_id' => 1, 'branch_id' => 1, 'user_id' => 1, 'insurance_company_id' => 1,
            'period' => '2026-07', 'type' => 'kilometers_batch', 'path' => 'batch.json',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Storage::swap(new class($documentId) {
            public function __construct(private int $documentId)
            {
            }

            public function disk(string $name): self
            {
                return $this;
            }

            public function exists(string $path): bool
            {
                return true;
            }

            public function delete(string $path): bool
            {
                \PHPUnit\Framework\Assert::assertNotNull(DB::table('documents')->where('id', $this->documentId)->value('deleted_at'));
                return true;
            }
        });

        (new DocumentService())->deleteManyDocumentsWithAssets([$documentId]);

        self::assertTrue(Document::withTrashed()->findOrFail($documentId)->trashed());
    }

    public function test_legacy_txt_uses_saved_owner_and_branch_and_does_not_enter_new_generator(): void
    {
        $payload = ['batchNumber' => '010325', 'batchType' => ['code' => 'N'],
            'period' => ['2026-03-01', '2026-03-31'], 'patients' => [['id' => 15]]];
        $this->storedFiles(['old.json' => json_encode($payload)]);
        $document = (new Document())->forceFill(['id' => 7, 'path' => 'old.json', 'type' => 'kilometers_batch',
            'user_id' => 1, 'branch_id' => 2, 'company_id' => 3, 'insurance_company_id' => 4]);
        $response = new Response('legacy');
        $legacy = $this->createMock(LegacyClaimExportService::class);
        $legacy->expects(self::once())->method('download')->with($payload + [
            'user' => ['id' => 1], 'branch' => ['id' => 2], 'company' => ['id' => 3], 'insurance' => ['id' => 4],
        ])->willReturn($response);
        Container::getInstance()->instance(LegacyClaimExportService::class, $legacy);
        self::assertSame($response, (new KilometersBatchDocumentService())->downloadStored($document));
    }

    public function test_new_txt_returns_exact_saved_bytes_without_any_recalculation(): void
    {
        $content = "saved|content|\r\n";
        $this->storedFiles(['new.json' => json_encode(['schema_version' => 2, 'batchNumber' => '010925', 'claim_content' => $content])]);
        $factory = $this->createMock(ResponseFactory::class);
        $factory->method('make')->willReturnCallback(fn ($body) => new Response($body));
        Container::getInstance()->instance(ResponseFactory::class, $factory);
        $document = (new Document())->forceFill(['id' => 8, 'path' => 'new.json', 'type' => 'kilometers_batch']);
        self::assertSame($content, (new KilometersBatchDocumentService())->downloadStored($document)->getContent());
    }

    public function test_original_form_can_save_without_car_or_manual_journey_selection(): void
    {
        $validator = Container::getInstance()->make('validator');
        foreach (['N', 'O', 'A', 'E', 'F', 'G', 'I', 'J', 'K'] as $type) {
            $result = $validator->make([
                'batchType' => ['code' => $type], 'insurance' => ['id' => 1], 'branch' => ['id' => 2],
                'period' => ['2026-09-01', '2026-09-30'], 'patients' => [['id' => 15]],
            ], (new StoreKilometersBatchRequest())->rules());
            self::assertTrue($result->passes(), $result->errors()->toJson());
        }
    }

    private function assignedCars(): RouteService
    {
        $this->database();
        Schema::table('cars', function ($table) {
            $table->integer('company_id');
            $table->integer('user_id');
        });
        DB::table('cars')->insert([
            ['id' => 1, 'company_id' => 1, 'user_id' => 2],
            ['id' => 2, 'company_id' => 2, 'user_id' => 1],
            ['id' => 3, 'company_id' => 1, 'user_id' => 1],
            ['id' => 4, 'company_id' => 1, 'user_id' => 1],
        ]);
        return new RouteService(new StopGrouper(), $this->createMock(RoutePlanner::class));
    }

    public function test_assigned_car_is_resolved_automatically_in_current_company(): void
    {
        $routes = $this->assignedCars();
        $actor = (new User())->forceFill(['id' => 1]);
        $branch = (new Branch())->forceFill(['id' => 1, 'company_id' => 1]);
        self::assertSame(3, $routes->car($actor, $branch)->id);
        self::assertSame(4, $routes->car($actor, $branch, 4)->id);
    }

    public function test_car_belonging_to_another_user_is_rejected(): void
    {
        $routes = $this->assignedCars();
        $this->expectException(ValidationException::class);
        $routes->car((new User())->forceFill(['id' => 1]), (new Branch())->forceFill(['company_id' => 1]), 1);
    }
}
