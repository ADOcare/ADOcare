<?php

namespace Tests\Unit;

use App\Services\Transport\ManualMonthlyDzcPlanner;
use App\Services\Transport\RoutePlanner;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class ManualMonthlyDzcPlannerTest extends TestCase
{
    private function config(): array
    {
        return [
            'month' => '2026-11',
            'driver' => ['name' => 'Test Driver'],
            'company' => ['name' => 'Test Ltd', 'ico' => '12345678'],
            'vehicle' => [
                'model' => 'Test', 'license_plate' => 'AB123CD',
                'odometer_start_km' => 1000, 'odometer_end_km' => 1020,
                'odometer_reading_date' => '2026-11-30',
            ],
            'base' => ['address' => 'Base', 'city' => 'Lučenec', 'latitude' => 48.33, 'longitude' => 19.66],
            'destinations' => [
                ['address' => 'A', 'city' => 'Lučenec', 'latitude' => 48.34, 'longitude' => 19.67, 'purpose' => 'Business A'],
                ['address' => 'B', 'city' => 'Lučenec', 'latitude' => 48.35, 'longitude' => 19.68, 'purpose' => 'Business B'],
            ],
            'planning' => [
                'strategy' => 'approximate_target', 'monthly_km_target' => 12,
                'tolerance_percent' => 5, 'max_stops_per_day' => 1,
                'max_trip_days' => 3, 'dates' => ['2026-11-02', '2026-11-04', '2026-11-06'],
            ],
            'output' => ['format' => 'pdf'],
        ];
    }

    private function fakeRoutes(): RoutePlanner
    {
        $routes = Mockery::mock(RoutePlanner::class);
        $routes->shouldReceive('plan')->andReturnUsing(function (array $base, array $stops, string $date): array {
            $result = [];
            $origin = ['address' => $base['address'], 'city' => $base['city']];
            foreach ($stops as $index => $stop) {
                $destination = ['address' => $stop['address'], 'city' => $stop['city']];
                $result[] = [
                    'stop_key' => $stop['key'], 'service_date' => $date,
                    'origin' => $origin, 'destination' => $destination,
                    'departure_time' => $date . ' 08:00:00',
                    'arrival_time' => $date . ' 08:20:00',
                    'distance_m' => 3000,
                    'patient_ids' => ['planned-stop'], 'is_return' => false,
                ];
                $origin = $destination;
            }
            $result[] = [
                'stop_key' => 'return', 'service_date' => $date,
                'origin' => $origin, 'destination' => ['address' => $base['address'], 'city' => $base['city']],
                'departure_time' => $date . ' 08:40:00',
                'arrival_time' => $date . ' 09:00:00',
                'distance_m' => 3000,
                'patient_ids' => ['planned-stop'], 'is_return' => true,
            ];
            return $result;
        });
        return $routes;
    }

    public function test_approximate_target_generates_only_proposals_and_schedules_spread_out_dates(): void
    {
        $planner = new ManualMonthlyDzcPlanner($this->fakeRoutes());
        $result = $planner->plan($this->config());

        $this->assertSame('planned', $result['status']);
        $this->assertSame(12.0, $result['month_totals']['distance_km']);
        $this->assertCount(2, $result['day_totals']);
        $this->assertCount(4, $result['legs']);
        $this->assertSame('2026-11-02', $result['day_totals'][0]['date']);
        $this->assertSame('2026-11-06', $result['day_totals'][1]['date']);
        $this->assertSame('NÁVRH - Business A', $result['legs'][0]['purpose']);
        $this->assertArrayNotHasKey('patient_ids', $result['legs'][0]);
    }

    public function test_ending_odometer_caps_possible_routes_even_when_target_is_higher(): void
    {
        $input = $this->config();
        $input['vehicle']['odometer_end_km'] = 1007;
        $planner = new ManualMonthlyDzcPlanner($this->fakeRoutes());
        $result = $planner->plan($input);

        $this->assertSame(6.0, $result['month_totals']['distance_km']);
        $this->assertNotEmpty($result['warnings']);
    }

    public function test_missing_coordinates_are_rejected_not_guessed(): void
    {
        $input = $this->config();
        $input['destinations'][0]['latitude'] = null;
        $planner = new ManualMonthlyDzcPlanner($this->fakeRoutes());

        $this->expectException(ValidationException::class);
        $planner->plan($input);
    }

    public function test_dates_outside_month_are_rejected(): void
    {
        $input = $this->config();
        $input['planning']['dates'] = ['2026-12-01'];
        $planner = new ManualMonthlyDzcPlanner($this->fakeRoutes());

        $this->expectException(ValidationException::class);
        $planner->plan($input);
    }
}
