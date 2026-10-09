<?php

namespace Tests\Transport;

use App\Services\Transport\OdometerCalculator;
use App\Services\Transport\RoutePlanner;
use App\Services\Transport\StopGrouper;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class RouteAndOdometerTest extends TransportTestCase
{
    public function test_same_address_is_one_stop_but_nearby_house_is_separate(): void
    {
        $base = ['city' => 'Nitra', 'latitude' => 48.31, 'longitude' => 18.08];
        $stops = (new StopGrouper())->group([
            $base + ['patient_id' => 2, 'address' => 'Hlavná 1'],
            array_replace($base, ['patient_id' => 1, 'address' => ' hlavná   1 ', 'longitude' => 18.08001]),
            array_replace($base, ['patient_id' => 3, 'address' => 'Hlavná 2', 'longitude' => 18.08002]),
        ]);
        self::assertCount(2, $stops);
        $patients = array_column($stops, 'patient_ids');
        self::assertContains([1, 2], $patients);
        self::assertContains([3], $patients);
    }

    public function test_invalid_coordinates_do_not_become_zero_kilometers(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new StopGrouper())->group([['patient_id' => 1, 'address' => 'A 1', 'city' => 'Nitra', 'latitude' => null, 'longitude' => 18]]);
    }

    public function test_odometer_is_calculated_backwards_without_distributing_difference(): void
    {
        $result = (new OdometerCalculator())->calculate([
            ['distance_m' => 10250], ['distance_m' => 1750], ['distance_m' => 8000],
        ], 1200, 1175);
        self::assertEquals(1180, $result['start_km']);
        self::assertEquals(5, $result['difference_km']);
        self::assertEquals([1180, 1190.25, 1192], array_column($result['legs'], 'odometer_start_km'));
        self::assertEquals([1190.25, 1192, 1200], array_column($result['legs'], 'odometer_end_km'));
        self::assertSame(['calculated', 'calculated', 'user_entered'], array_column($result['legs'], 'odometer_end_source'));
        self::assertSame([10250, 1750, 8000], array_column($result['legs'], 'distance_m'));
    }

    public function test_negative_derived_odometer_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new OdometerCalculator())->calculate([['distance_m' => 1001]], 1);
    }

    public function test_negative_difference_remains_visible_instead_of_rewriting_prior_reading(): void
    {
        $result = (new OdometerCalculator())->calculate([['distance_m' => 30000]], 1200, 1180);
        self::assertEquals(-10, $result['difference_km']);
        self::assertEquals(1180, $result['previous_km']);
        self::assertEquals(1170, $result['start_km']);
    }

    public function test_decreasing_entered_odometer_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new OdometerCalculator())->calculate([['distance_m' => 1000]], 1200, 1201);
    }

    public function test_route_includes_every_stop_and_return_with_continuous_origins(): void
    {
        $branch = ['address' => 'Základňa 1', 'city' => 'Nitra', 'latitude' => 48, 'longitude' => 18];
        $stops = [
            ['key' => 'a', 'address' => 'A 1', 'city' => 'Nitra', 'latitude' => 48.01, 'longitude' => 18.01, 'patient_ids' => [1, 2]],
            ['key' => 'b', 'address' => 'B 2', 'city' => 'Nitra', 'latitude' => 48.02, 'longitude' => 18.02, 'patient_ids' => [3]],
        ];
        $start = CarbonImmutable::parse('2026-09-01 08:00:00', 'Europe/Bratislava')->timestamp;
        $raw = [
            ['end' => [18.01, 48.01], 'length' => 1000, 'timestamps' => [
                'leave_start_point' => $start, 'arrive_end_point' => $start + 120, 'leave_end_point' => $start + 1320]],
            ['end' => [18.02, 48.02], 'length' => 2000, 'timestamps' => [
                'leave_start_point' => $start + 1320, 'arrive_end_point' => $start + 1440, 'leave_end_point' => $start + 2040]],
            ['end' => [18, 48], 'length' => 3000, 'timestamps' => [
                'leave_start_point' => $start + 2040, 'arrive_end_point' => $start + 2160]],
        ];
        $legs = (new RoutePlanner())->mapLegs($branch, $stops, $raw, '2026-09-01', $start, 600);
        self::assertCount(3, $legs);
        self::assertSame(['Základňa 1', 'A 1', 'B 2'], array_column(array_column($legs, 'origin'), 'address'));
        self::assertSame(['A 1', 'B 2', 'Základňa 1'], array_column(array_column($legs, 'destination'), 'address'));
        self::assertSame([false, false, true], array_column($legs, 'is_return'));
        self::assertSame([1, 2], $legs[0]['patient_ids']);
        self::assertSame([3], $legs[2]['patient_ids']);
        self::assertSame(6000, array_sum(array_column($legs, 'distance_m')));
        self::assertSame(1200, $legs[0]['service_seconds']);
        self::assertSame([120, 120, 120], array_column($legs, 'travel_seconds'));
        self::assertSame([0, 0, 0], array_column($legs, 'waiting_seconds'));
        self::assertSame('2026-09-01 08:36:00', $legs[2]['arrival_time']);
        $this->expectException(ValidationException::class);
        (new RoutePlanner())->mapLegs($branch, $stops, array_slice($raw, 0, 2), '2026-09-01', $start, 600);
    }

    private function reportedTimingCase(): array
    {
        // Consecutive timestamps from the reported failure. The first stop has three patients,
        // but the solver applied only 600 seconds there instead of the requested 1800 seconds.
        return [
            ['address' => 'Základňa 1', 'city' => 'Nitra', 'latitude' => 48, 'longitude' => 18],
            [
                ['key' => 'a', 'address' => 'A 1', 'city' => 'Nitra', 'latitude' => 48.01, 'longitude' => 18.01, 'patient_ids' => [1, 2, 3]],
                ['key' => 'b', 'address' => 'B 2', 'city' => 'Nitra', 'latitude' => 48.02, 'longitude' => 18.02, 'patient_ids' => [4]],
            ],
            [
                ['end' => [18.01, 48.01], 'length' => 1000, 'timestamps' => [
                    'leave_start_point' => 1788252171, 'arrive_end_point' => 1788252599, 'leave_end_point' => 1788253199]],
                ['end' => [18.02, 48.02], 'length' => 0, 'timestamps' => [
                    'leave_start_point' => 1788253199, 'arrive_end_point' => 1788253199, 'leave_end_point' => 1788253799]],
                ['end' => [18, 48], 'length' => 500, 'timestamps' => [
                    'leave_start_point' => 1788253799, 'arrive_end_point' => 1788253859]],
            ],
            1788252171,
        ];
    }

    public function test_reported_grouped_stop_preserves_travel_and_counts_care_once_per_patient(): void
    {
        [$branch, $stops, $raw, $start] = $this->reportedTimingCase();
        $legs = (new RoutePlanner())->mapLegs($branch, $stops, $raw, '2026-09-01', $start, 600);
        self::assertSame([428, 0, 60], array_column($legs, 'travel_seconds'));
        self::assertSame([1800, 600, 0], array_column($legs, 'service_seconds'));
        self::assertSame([1000, 0, 500], array_column($legs, 'distance_m'));
        self::assertSame(['a', 'b', 'return'], array_column($legs, 'stop_key'));
        self::assertSame(1788254399, CarbonImmutable::parse($legs[1]['arrival_time'], 'Europe/Bratislava')->timestamp);
        self::assertSame(1788255059, CarbonImmutable::parse($legs[2]['arrival_time'], 'Europe/Bratislava')->timestamp);
        self::assertSame('calculated', $legs[1]['source']);
        self::assertSame($legs, (new RoutePlanner())->mapLegs($branch, $stops, $raw, '2026-09-01', $start, 600));
    }

    public function test_explicit_solver_wait_is_preserved_separately_from_travel_and_care(): void
    {
        [$branch, $stops, $raw, $start] = $this->reportedTimingCase();
        foreach ([1, 2] as $index) {
            foreach ($raw[$index]['timestamps'] as &$time) {
                $time += 30;
            }
            unset($time);
        }
        $legs = (new RoutePlanner())->mapLegs($branch, $stops, $raw, '2026-09-01', $start, 600);
        self::assertSame([0, 30, 0], array_column($legs, 'waiting_seconds'));
        self::assertSame([428, 0, 60], array_column($legs, 'travel_seconds'));
        self::assertSame(1788255089, CarbonImmutable::parse($legs[2]['arrival_time'], 'Europe/Bratislava')->timestamp);
    }

    public function test_negative_travel_time_is_still_rejected(): void
    {
        [$branch, $stops, $raw, $start] = $this->reportedTimingCase();
        $raw[1]['timestamps']['arrive_end_point']--;
        $this->expectException(ValidationException::class);
        (new RoutePlanner())->mapLegs($branch, $stops, $raw, '2026-09-01', $start, 600);
    }

    public function test_departure_before_solver_finished_previous_stop_is_rejected(): void
    {
        [$branch, $stops, $raw, $start] = $this->reportedTimingCase();
        $raw[1]['timestamps']['leave_start_point']--;
        $this->expectException(ValidationException::class);
        (new RoutePlanner())->mapLegs($branch, $stops, $raw, '2026-09-01', $start, 600);
    }

    public function test_missing_departure_does_not_invent_a_zero_duration(): void
    {
        [$branch, $stops, $raw, $start] = $this->reportedTimingCase();
        unset($raw[1]['timestamps']['leave_start_point']);
        $this->expectException(ValidationException::class);
        (new RoutePlanner())->mapLegs($branch, $stops, $raw, '2026-09-01', $start, 600);
    }

    public function test_calculated_route_over_24_hours_is_still_rejected(): void
    {
        [$branch, $stops, $raw, $start] = $this->reportedTimingCase();
        $this->expectException(ValidationException::class);
        (new RoutePlanner())->mapLegs($branch, $stops, $raw, '2026-09-01', $start, 30000);
    }
}
