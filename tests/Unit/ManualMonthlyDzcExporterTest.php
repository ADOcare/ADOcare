<?php

namespace Tests\Unit;

use App\Services\DZCDocumentService;
use App\Services\Transport\DzcExportService;
use App\Services\Transport\ManualMonthlyDzcExporter;
use App\Services\Transport\OdometerCalculator;
use App\Services\Transport\XlsxWriter;
use Mockery;
use Tests\TestCase;

class ManualMonthlyDzcExporterTest extends TestCase
{
    public function test_existing_xlsx_journey_columns_and_actual_odometer_are_kept_separate(): void
    {
        $odometer = new OdometerCalculator();
        $writer = Mockery::mock(XlsxWriter::class);
        $existing = new DzcExportService(Mockery::mock(DZCDocumentService::class), $odometer, $writer);
        $service = new ManualMonthlyDzcExporter($odometer, $existing, $writer);
        $plan = [
            'year' => '2026', 'month' => '11', 'user_name' => 'Test Driver',
            'company_name' => 'Test Ltd', 'company_ico' => '12345678',
            'car_model' => 'Test car', 'car_license_plate' => 'AB123CD', 'car_vin' => '',
            'start_date' => '2026-11-01', 'end_date' => '2026-11-30',
            'last_journey_date' => '2026-11-25', 'odometer_reading_date' => '2026-11-30',
            'odometer_actual_start_km' => 1000, 'odometer_actual_end_km' => 1010,
            'warnings' => [],
            'month_totals' => ['target_km' => 8, 'distance_km' => 8, 'difference_km' => 0, 'tolerance_percent' => 5],
            'day_totals' => [['date' => '2026-11-25', 'stops' => 1, 'distance_km' => 8, 'total_time' => '01:00:00']],
            'legs' => [
                [
                    'service_date' => '2026-11-25', 'departure_time' => '2026-11-25 08:00:00',
                    'arrival_time' => '2026-11-25 08:30:00', 'origin' => ['address' => 'A', 'city' => 'L'],
                    'destination' => ['address' => 'B', 'city' => 'L'], 'purpose' => 'NÁVRH - test',
                    'distance_m' => 4000,
                ],
                [
                    'service_date' => '2026-11-25', 'departure_time' => '2026-11-25 08:40:00',
                    'arrival_time' => '2026-11-25 09:00:00', 'origin' => ['address' => 'B', 'city' => 'L'],
                    'destination' => ['address' => 'A', 'city' => 'L'], 'purpose' => 'NÁVRH - návrat',
                    'distance_m' => 4000,
                ],
            ],
        ];
        $sheets = $service->sheets($plan);

        $this->assertCount(3, $sheets);
        $this->assertSame('Stav tachometera pred jazdou', $sheets['Jazdy'][0][10]);
        $this->assertSame(1000, $sheets['Jazdy'][1][10]);
        $this->assertSame(1008, $sheets['Jazdy'][2][11]);
        $metadata = array_column($sheets['Vozidlo a údaje'], 1, 0);
        $this->assertSame(1010, $metadata['Skutočný koncový tachometer za mesiac']);
        $this->assertSame(2, $metadata['Nevysvetlené kilometre mimo plánovaných jázd']);
        $this->assertSame(1008, $metadata['Odhadovaný stav tachometra po poslednej plánovanej jazde']);
    }
}
