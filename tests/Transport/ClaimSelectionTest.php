<?php

namespace Tests\Transport;

use App\Models\Branch;
use App\Models\Car;
use App\Models\User;
use App\Services\Claims\InsuredClaimResolver;
use App\Services\Transport\ClaimSelectionService;
use App\Services\Transport\RouteService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClaimSelectionTest extends TransportTestCase
{
    private User $actor;
    private Branch $branch;
    private array $days;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database();
        $this->actor = (new User())->forceFill(['id' => 1]);
        $this->branch = (new Branch())->forceFill(['id' => 1, 'company_id' => 1]);
        $this->days = [['date' => '2026-09-01', 'fingerprint' => str_repeat('a', 64), 'legs' => [
            ['journey_id' => 51, 'is_return' => false, 'distance_m' => 10000, 'patient_ids' => [1, 2],
                'origin' => ['address' => 'Základňa 1', 'city' => 'Nitra'], 'destination' => ['address' => 'A 1', 'city' => 'Nitra']],
            ['journey_id' => null, 'is_return' => true, 'distance_m' => 10000, 'patient_ids' => []],
        ]]];
    }

    private function point(int $id, int $patient, string $category = 'domestic'): object
    {
        return (object) [
            'id' => $id, 'patient_id' => $patient, 'date' => '2026-09-01', 'first_name' => 'Test', 'last_name' => 'Pacient',
            'procedure_code' => '3439', 'diagnosis_code' => 'I10', 'personal_number' => '1234567890', 'price' => '0.50',
            'category' => $category, 'identification_method' => $category === 'domestic' ? 'slovak_identifier' : 'foreign_triad',
            'member_state_code' => 'CZ', 'foreign_insured_id' => 'CZ001', 'sex' => 'F',
            'created_at' => '2026-09-01 08:00:00', 'updated_at' => '2026-09-01 08:00:00',
        ];
    }

    private function select(string $type, array $rows, array $data = [], bool $preview = true, int $insurer = 1, array $patientIds = []): array
    {
        $routes = $this->createMock(RouteService::class);
        $routes->method('car')->willReturn((new Car())->forceFill(['id' => 1, 'evc' => 'NR123AB', 'model' => 'Test', 'vin' => null]));
        $routes->method('days')->willReturn($this->days);
        return (new ClaimSelectionService($routes, new InsuredClaimResolver()))->select([
            'type' => $type, 'from' => '2026-09-01', 'to' => '2026-09-30', 'insuranceId' => $insurer,
            'insuranceCode' => '25', 'patientIds' => $patientIds, 'rows' => collect($rows), 'data' => $data,
        ], $this->actor, $this->branch, $preview);
    }

    private function save(array $line, string $character = 'N', int $insurer = 1): int
    {
        $documentId = 100 + DB::table('transport_claim_batches')->count();
        DB::table('documents')->insert([
            'id' => $documentId, 'company_id' => 1, 'branch_id' => 1, 'user_id' => 1,
            'insurance_company_id' => $insurer, 'period' => '2026-09', 'type' => 'kilometers_batch',
        ]);
        $id = DB::table('transport_claim_batches')->insertGetId([
            'document_id' => $documentId, 'company_id' => 1, 'branch_id' => 1, 'user_id' => 1,
            'insurance_company_id' => $insurer, 'period' => '2026-09', 'character' => $character, 'revision' => 1, 'payload' => '{}',
        ]);
        DB::table('transport_claim_lines')->insert([
            'batch_id' => $id, 'revision' => 1, 'journey_id' => $line['journey_id'], 'insurance_company_id' => $insurer,
            'patient_id' => $line['patient_id'], 'patient_point_id' => $line['point_id'], 'character' => $character,
            'fingerprint' => $line['fingerprint'], 'payload' => json_encode($line, JSON_THROW_ON_ERROR),
        ]);

        return $documentId;
    }

    public function test_one_stop_has_one_representative_across_domestic_and_eu_batches(): void
    {
        $rows = [$this->point(11, 1), $this->point(12, 2, 'eu')];
        $domestic = $this->select('N', $rows);
        self::assertCount(1, $domestic['selected']);
        self::assertSame(1, $domestic['selected'][0]['patient_id']);
        self::assertSame(10, $domestic['selected'][0]['kilometers']);
        self::assertSame([], $this->select('E', $rows)['selected']);
        // A different insurer is allowed its own representative at the same stop.
        self::assertCount(1, $this->select('E', [$rows[1]], insurer: 2)['selected']);
    }

    public function test_addition_does_not_charge_a_new_patient_at_an_already_billed_stop(): void
    {
        $original = $this->point(11, 1);
        $this->save($this->select('N', [$original])['selected'][0]);
        self::assertSame([], $this->select('A', [$original, $this->point(12, 2)])['selected']);
        self::assertFalse($this->select('N', [$original])['can_create']);
    }

    public function test_new_batch_can_be_recreated_after_its_document_is_deleted(): void
    {
        $point = $this->point(11, 1);
        $original = $this->select('N', [$point])['selected'][0];
        $documentId = $this->save($original);

        DB::table('documents')->where('id', $documentId)->update(['deleted_at' => now()]);

        $replacement = $this->select('N', [$point]);

        self::assertTrue($replacement['can_create']);
        self::assertCount(1, $replacement['selected']);
    }

    public function test_correction_keeps_journey_and_patient_when_an_earlier_point_is_added(): void
    {
        $original = $this->point(11, 1);
        $this->save($this->select('N', [$original])['selected'][0]);
        $original->diagnosis_code = 'I11';
        $correction = $this->select('O', [$this->point(10, 2), $original]);
        self::assertCount(1, $correction['selected']);
        self::assertSame(51, $correction['selected'][0]['journey_id']);
        self::assertSame(1, $correction['selected'][0]['patient_id']);
        self::assertTrue($correction['selected'][0]['edited']);
    }

    public function test_unchanged_rejected_claim_can_be_selected_for_correction(): void
    {
        $point = $this->point(11, 1);
        $this->save($this->select('N', [$point])['selected'][0]);
        self::assertSame([], $this->select('O', [$point])['selected']);
        self::assertCount(1, $this->select('O', [$point], ['journeyIds' => [51]])['selected']);
    }

    public function test_original_patient_selection_includes_unchanged_correction_but_never_duplicate_addition(): void
    {
        $point = $this->point(11, 1);
        $this->save($this->select('N', [$point])['selected'][0]);
        $correction = $this->select('O', [$point], preview: false, patientIds: [1]);
        self::assertCount(1, $correction['selected']);
        self::assertSame(51, $correction['selected'][0]['journey_id']);
        self::assertSame([], $this->select('O', [$point], patientIds: [2])['selected']);
        self::assertSame([], $this->select('O', [$point], ['journeyIds' => []], patientIds: [1])['selected']);
        self::assertSame([], $this->select('A', [$point], patientIds: [1])['selected']);
    }

    public function test_route_change_blocks_addition_and_does_not_rewrite_history(): void
    {
        $point = $this->point(11, 1);
        $this->save($this->select('N', [$point])['selected'][0]);
        $this->days[0]['legs'][0]['distance_m'] = 12000;
        self::assertFalse($this->select('A', [$point])['can_create']);
        self::assertSame(1, DB::table('transport_claim_lines')->count());
        $this->expectException(ValidationException::class);
        $this->select('A', [$point], preview: false);
    }

    public function test_moving_address_is_not_a_new_additive_journey(): void
    {
        $point = $this->point(11, 1);
        $this->save($this->select('N', [$point])['selected'][0]);
        $this->days[0]['legs'][0]['journey_id'] = 52;
        $this->days[0]['legs'][0]['destination']['address'] = 'A 2';
        $result = $this->select('A', [$point]);
        self::assertFalse($result['can_create']);
        self::assertSame([], $result['selected']);
        self::assertNotEmpty($result['blocked']);
    }

    public function test_new_addition_does_not_require_an_artificial_empty_new_batch(): void
    {
        self::assertCount(1, $this->select('A', [$this->point(11, 1)])['selected']);
    }

    public function test_stale_preview_is_rejected(): void
    {
        $point = $this->point(11, 1);
        $preview = $this->select('N', [$point]);
        $point->diagnosis_code = 'I11';
        $this->expectException(ValidationException::class);
        $this->select('N', [$point], ['journeyIds' => [51], 'previewToken' => $preview['preview_token']], false);
    }
}
