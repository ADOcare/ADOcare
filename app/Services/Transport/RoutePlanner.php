<?php

namespace App\Services\Transport;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class RoutePlanner
{
    public function plan(array $branch, array $stops, string $date): array
    {
        $start = CarbonImmutable::parse($date . ' ' . (($branch['terrain_start_time'] ?? null) ?: '08:00:00'), 'Europe/Bratislava');
        $base = [$branch['longitude'], $branch['latitude']];
        $spending = (int) ($branch['per_location_time'] ?? 0);
        $spending = $spending > 0 ? $spending : 10;
        // The existing application stores this branch setting in minutes.
        $spending *= 60;
        $payload = [
            'start_location' => $base,
            'end_location' => $base,
            'points_locations' => array_map(fn ($stop) => [$stop['longitude'], $stop['latitude']], $stops),
            'start_time' => $start->timestamp,
            'timeSpending' => array_map(fn ($stop) => $spending * count($stop['patient_ids']), $stops),
        ];
        $baseUrl = rtrim((string) config('services.route_service.base_url'), '/');
        if ($baseUrl === '') {
            $this->fail('Nie je nastavená služba na výpočet trasy.');
        }
        try {
            $response = Http::timeout((int) config('services.route_service.timeout', 30))
                ->acceptJson()->withBody(json_encode($payload, JSON_THROW_ON_ERROR), 'application/json')
                ->send('GET', $baseUrl . '/' . ltrim(config('services.route_service.endpoint', '/tsp-solver'), '/'));
            $rawLegs = $response->successful() ? $response->json('response') : null;
        } catch (\Throwable $error) {
            $this->fail('Výpočet trasy nie je dostupný. Skúste ho znova; vzdialenosti neboli nahradené nulami.');
        }
        if (!is_array($rawLegs) || count($rawLegs) !== count($stops) + 1) {
            $this->fail('Služba nevrátila všetky zastávky a návrat na základňu pre ' . $date . '.');
        }
        return $this->mapLegs($branch, $stops, $rawLegs, $date, $start->timestamp, $spending);
    }

    public function mapLegs(array $branch, array $stops, array $rawLegs, string $date, int $startUnix, int $spending): array
    {
        $remaining = $stops;
        $origin = ['address' => $branch['address'], 'city' => $branch['city']];
        $cursor = $startUnix;
        $solverCursor = $startUnix;
        $legs = [];
        foreach ($rawLegs as $index => $raw) {
            $end = $raw['end'] ?? null;
            $meters = $raw['length'] ?? null;
            if (!is_array($end) || count($end) !== 2 || !is_numeric($meters) || !is_finite((float) $meters) || $meters < 0) {
                $this->fail('Neplatná odpoveď výpočtu trasy pre ' . $date . '.');
            }
            $isReturn = $index === count($rawLegs) - 1;
            if ($isReturn) {
                if ($remaining !== [] || !$this->sameCoordinates($end, [$branch['longitude'], $branch['latitude']])) {
                    $this->fail('Trasa neobsahuje všetky zastávky alebo návrat do prevádzky.');
                }
                $lastLeg = $legs[array_key_last($legs)] ?? null;
                $stop = [
                    'key' => 'return',
                    'address' => $branch['address'],
                    'city' => $branch['city'],
                    'patient_ids' => $lastLeg['patient_ids'] ?? [],
                ];
            } else {
                $matches = array_keys(array_filter($remaining, fn ($stop) => $this->sameCoordinates($end, [$stop['longitude'], $stop['latitude']])));
                if ($matches === []) {
                    $this->fail('Cieľ úseku sa nezhoduje so žiadnou zostávajúcou návštevou.');
                }
                $match = $matches[0];
                $stop = $remaining[$match];
                unset($remaining[$match]);
            }
            // The solver's dwell time can differ from the time requested for a grouped stop.
            // Keep its segment duration and order, then build a separate calculated timeline
            // with the application's per-patient care time. Never compare a raw solver arrival
            // with a cursor that already includes a different amount of care time.
            $solverDeparture = $this->timestamp($raw, 'leave_start_point', $date, $index + 1, $startUnix);
            $solverArrival = $this->timestamp($raw, 'arrive_end_point', $date, $index + 1, $startUnix);
            $solverLeave = $isReturn
                ? $solverArrival
                : $this->timestamp($raw, 'leave_end_point', $date, $index + 1, $startUnix);
            if ($solverDeparture < $solverCursor || $solverArrival < $solverDeparture || $solverLeave < $solverArrival) {
                $this->fail('TSP vrátilo obrátené časové poradie pre ' . $date . ', úsek ' . ($index + 1) . '.');
            }
            $travelSeconds = $solverArrival - $solverDeparture;
            $waitingSeconds = $solverDeparture - $solverCursor;
            $serviceSeconds = $isReturn ? 0 : $spending * count($stop['patient_ids']);
            $departure = $cursor + $waitingSeconds;
            $arrival = $departure + $travelSeconds;
            if ($arrival + $serviceSeconds > $startUnix + 86400) {
                $this->fail('Vypočítaná trasa vrátane ošetrovania pacientov presahuje 24 hodín pre ' . $date . '. Skontrolujte návštevy a čas na pacienta.');
            }
            $legs[] = [
                'sequence' => $index + 1,
                'service_date' => $date,
                'stop_key' => $stop['key'],
                'patient_ids' => $stop['patient_ids'],
                'origin' => $origin,
                'destination' => ['address' => $stop['address'], 'city' => $stop['city']],
                'departure_time' => CarbonImmutable::createFromTimestamp($departure, 'Europe/Bratislava')->format('Y-m-d H:i:s'),
                'arrival_time' => CarbonImmutable::createFromTimestamp($arrival, 'Europe/Bratislava')->format('Y-m-d H:i:s'),
                'distance_m' => (int) round((float) $meters),
                'travel_seconds' => $travelSeconds,
                'waiting_seconds' => $waitingSeconds,
                'service_seconds' => $serviceSeconds,
                'is_return' => $isReturn,
                'source' => 'calculated',
            ];
            $cursor = $arrival + $serviceSeconds;
            $solverCursor = $solverLeave;
            $origin = $legs[array_key_last($legs)]['destination'];
        }
        return $legs;
    }

    private function timestamp(array $raw, string $field, string $date, int $sequence, int $startUnix): int
    {
        $value = data_get($raw, 'timestamps.' . $field);
        if (!is_numeric($value) || !is_finite((float) $value)
            || (float) $value < $startUnix || (float) $value > $startUnix + 86400) {
            $this->fail('TSP nevrátilo platný čas ' . $field . ' v Unix sekundách pre ' . $date . ', úsek ' . $sequence . '.');
        }
        return (int) $value;
    }

    private function sameCoordinates(array $a, array $b): bool
    {
        return is_numeric($a[0]) && is_numeric($a[1])
            && abs((float) $a[0] - (float) $b[0]) < 0.00001
            && abs((float) $a[1] - (float) $b[1]) < 0.00001;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['transport' => [$message]]);
    }
}
