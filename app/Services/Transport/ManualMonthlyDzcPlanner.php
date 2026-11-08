<?php

namespace App\Services\Transport;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Hypothetical monthly itinerary based on road distances, not actual trips.
 * Each planned visit starts and ends at the supplied base location. The final
 * route legs remain compatible with existing ADOcare PDF and XLSX exporters.
 */
class ManualMonthlyDzcPlanner
{
    private const DEFAULT_MAX_DAILY_VISITS = 6;
    private const DEFAULT_MAX_DAILY_HOURS = 12;
    private const DEFAULT_STOP_MINUTES = 20;
    private const BETWEEN_TRIPS_MINUTES = 10;
    private const DEFAULT_TOLERANCE_PERCENT = 5;

    public function __construct(
        private RoutePlanner $routes,
        private ?MonthlyMileageOptimizer $optimizer = null
    ) {
        $this->optimizer ??= new MonthlyMileageOptimizer();
    }

    public function plan(array $config): array
    {
        $data = Validator::make($config, [
            'month' => ['required', 'date_format:Y-m'],
            'driver.name' => ['required', 'string', 'max:200'],
            'company.name' => ['nullable', 'string', 'max:200'],
            'company.ico' => ['nullable', 'string', 'max:20'],
            'vehicle.model' => ['required', 'string', 'max:150'],
            'vehicle.license_plate' => ['required', 'string', 'max:30'],
            'vehicle.vin' => ['nullable', 'string', 'max:50'],
            'vehicle.consumption_l_per_100km' => ['nullable', 'numeric', 'between:0,100'],
            'vehicle.odometer_start_km' => ['nullable', 'integer', 'min:0'],
            'vehicle.odometer_end_km' => ['nullable', 'integer', 'min:0'],
            'vehicle.odometer_reading_date' => ['nullable', 'date_format:Y-m-d'],
            'base.address' => ['required', 'string', 'max:300'],
            'base.city' => ['nullable', 'string', 'max:150'],
            'base.latitude' => ['required', 'numeric', 'between:-90,90'],
            'base.longitude' => ['required', 'numeric', 'between:-180,180'],
            'destinations' => ['required', 'array', 'min:1', 'max:60'],
            'destinations.*.name' => ['nullable', 'string', 'max:150'],
            'destinations.*.address' => ['required', 'string', 'max:300'],
            'destinations.*.city' => ['nullable', 'string', 'max:150'],
            'destinations.*.latitude' => ['required', 'numeric', 'between:-90,90'],
            'destinations.*.longitude' => ['required', 'numeric', 'between:-180,180'],
            'destinations.*.purpose' => ['nullable', 'string', 'max:250'],
            'destinations.*.max_visits_per_month' => ['nullable', 'integer', 'between:1,31'],
            'planning' => ['required', 'array'],
            'planning.monthly_km_target' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'planning.max_daily_visits' => ['sometimes', 'integer', 'between:1,12'],
            // Backward compatibility for existing JSON files.
            'planning.max_stops_per_day' => ['sometimes', 'integer', 'between:1,12'],
            'planning.max_daily_hours' => ['sometimes', 'numeric', 'between:1,24'],
            'planning.weekdays_only' => ['sometimes', 'boolean'],
            'planning.dates' => ['sometimes', 'array', 'min:1', 'max:31'],
            'planning.dates.*' => ['date_format:Y-m-d'],
            'planning.excluded_dates' => ['sometimes', 'array', 'max:31'],
            'planning.excluded_dates.*' => ['date_format:Y-m-d'],
            'planning.departure_time' => ['sometimes', 'date_format:H:i'],
            'planning.departure_time_window' => ['sometimes', 'array'],
            'planning.departure_time_window.from' => ['required_with:planning.departure_time_window', 'date_format:H:i'],
            'planning.departure_time_window.to' => ['required_with:planning.departure_time_window', 'date_format:H:i'],
            'planning.departure_time_window.step_minutes' => ['sometimes', 'integer', 'between:1,60'],
            'output.format' => ['sometimes', 'in:pdf,xlsx,both'],
        ])->validate();

        $month = CarbonImmutable::createFromFormat('!Y-m', $data['month'], 'Europe/Bratislava');
        $planning = $data['planning'];
        $dates = $this->dates($month, $planning);
        $readingDate = $data['vehicle']['odometer_reading_date'] ?? $month->endOfMonth()->toDateString();
        if (substr($readingDate, 0, 7) !== $data['month']) {
            $this->fail('vehicle.odometer_reading_date', 'Odčítanie tachometra musí patriť do plánovaného mesiaca.');
        }
        $dates = array_values(array_filter($dates, static fn (string $day): bool => $day <= $readingDate));
        if ($dates === []) {
            $this->fail('planning.dates', 'Nie sú dostupné dátumy pred odčítaním tachometra.');
        }
        $startKm = $data['vehicle']['odometer_start_km'] ?? null;
        $endKm = $data['vehicle']['odometer_end_km'] ?? null;
        if ($startKm !== null && $endKm !== null && $endKm < $startKm) {
            $this->fail('vehicle.odometer_end_km', 'Konečný stav musí byť >= počiatočnému.');
        }
        if (isset($planning['departure_time'], $planning['departure_time_window'])) {
            $this->fail('planning.departure_time', 'Zadajte čas alebo interval, nie oboje.');
        }
        $window = $planning['departure_time_window'] ?? null;
        if ($window !== null && $window['from'] > $window['to']) {
            $this->fail('planning.departure_time_window', 'Začiatok intervalu je po konci intervalu.');
        }
        $base = $this->location($data['base']);
        $destinations = [];
        $seen = [];
        foreach ($data['destinations'] as $index => $entry) {
            $destination = $this->location($entry);
            $rawKey = $destination['address'] . '|' . $destination['city'];
            $key = function_exists('mb_strtolower') ? mb_strtolower($rawKey, 'UTF-8') : strtolower($rawKey);
            if (isset($seen[$key])) {
                $this->fail('destinations', 'Duplicitná adresa: ' . $destination['address']);
            }
            $seen[$key] = true;
            if (abs($destination['latitude'] - $base['latitude']) < 0.00001 &&
                abs($destination['longitude'] - $base['longitude']) < 0.00001) {
                $this->fail('destinations', 'Adresa cieľa sa zhoduje so základňou: ' . $destination['address']);
            }
            $destination['id'] = $index;
            $destination['purpose'] = $entry['purpose'] ?? 'Pracovná návšteva';
            $destination['max_visits_per_month'] = $entry['max_visits_per_month'] ?? null;
            $destination['key'] = 'stop-' . $index;
            $destination['patient_ids'] = ['planned'];
            $destinations[] = $destination;
        }
        $visitLimit = (int) ($planning['max_daily_visits'] ?? $planning['max_stops_per_day'] ?? self::DEFAULT_MAX_DAILY_VISITS);
        $limitSeconds = (int) round(3600 * (float) ($planning['max_daily_hours'] ?? self::DEFAULT_MAX_DAILY_HOURS));
        $stopMinutes = (int) ($planning['stop_duration_minutes'] ?? self::DEFAULT_STOP_MINUTES);
        $startTimes = $this->startTimes($dates, $planning, $data['month']);
        $seed = (int) sprintf('%u', crc32($data['month'] . '|' . $base['address']));
        $target = (int) round($planning['monthly_km_target'] * 1000);
        $tolerance = (float) ($planning['tolerance_percent'] ?? self::DEFAULT_TOLERANCE_PERCENT);
        $maxMeters = (int) round($target * (1 + $tolerance / 100));
        $warnings = [];
        if ($startKm !== null && $endKm !== null) {
            $maxMeters = min($maxMeters, ((int) $endKm - (int) $startKm) * 1000);
            if (($endKm - $startKm) * 1000 < $target) {
                $warnings[] = 'Rozdiel tachometra je menší ako cieľ: plán sa nemôže považovať za odôvodnenie iných kilometrov.';
            }
        }
        if ($maxMeters <= 0) {
            $this->fail('planning', 'Na plánovanie nezostáva kladný rozsah kilometrov.');
        }

        // RoutePlanner already uses the road network and handles the precise
        // longitude/latitude order. For one stop, TSP has no order to optimize.
        // The estimate is only used for selecting trip combinations. Every
        // planned excursion is routed again at its actual proposed departure.
        $options = [];
        foreach ($destinations as $destination) {
            $startingBase = $base;
            $startingBase['terrain_start_time'] = '08:00:00';
            $startingBase['per_location_time'] = $stopMinutes;
            $route = $this->routes->plan($startingBase, [$destination], $dates[0]);
            if (count($route) !== 2 || !$route[1]['is_return']) {
                $this->fail('transport', 'Neplatná spiatočná cesta pre ' . $this->label($destination));
            }
            $meters = array_sum(array_column($route, 'distance_m'));
            $duration = $this->secondsBetween($route[0]['departure_time'], $route[1]['arrival_time']);
            if ($meters <= 0 || $duration <= 0) {
                $this->fail('transport', 'Nulová vzdialenosť alebo čas pre ' . $this->label($destination));
            }
            $options[] = [
                'distance_m' => $meters,
                'duration_s' => $duration,
                'max_visits_per_month' => $destination['max_visits_per_month'],
            ];
        }
        $schedule = $this->optimizer->optimize(
            $options,
            $dates,
            $target,
            $visitLimit,
            $limitSeconds,
            self::BETWEEN_TRIPS_MINUTES * 60,
            $maxMeters,
            $seed
        );

        $byDay = [];
        $dayTotals = [];
        $legs = [];
        $counts = array_fill(0, count($destinations), 0);
        $totalMeters = 0;
        $totalStops = 0;
        foreach ($schedule as $date => $ids) {
            if ($ids === []) {
                continue;
            }
            if (count($ids) > $visitLimit) {
                $this->fail('planning.max_daily_visits', 'Prekročený počet denných návštev.');
            }
            $cursor = CarbonImmutable::parse($date . ' ' . $startTimes[$date], 'Europe/Bratislava');
            $firstDeparture = $cursor;
            $addresses = [];
            $meters = 0;
            foreach ($ids as $tripIndex => $id) {
                $counts[$id]++;
                $dailyBase = $base;
                $dailyBase['terrain_start_time'] = $cursor->format('H:i:s');
                $dailyBase['per_location_time'] = $stopMinutes;
                $route = $this->routes->plan($dailyBase, [$destinations[$id]], $date);
                if (count($route) !== 2 || !$route[1]['is_return']) {
                    $this->fail('transport', 'TSP nevrátilo celý spiatočný okruh pre ' . $date);
                }
                $addresses[] = [
                    'type' => 'branch_start',
                    'address' => $this->label($base),
                    'arrival_time' => $route[0]['departure_time'],
                    'kilometers' => 0,
                ];
                foreach ($route as $leg) {
                    $meters += $leg['distance_m'];
                    $addresses[] = [
                        'type' => $leg['is_return'] ? 'branch_end' : 'destination',
                        'address' => $this->label($leg['destination']),
                        'arrival_time' => $leg['arrival_time'],
                        'kilometers' => round($leg['distance_m'] / 1000, 3),
                    ];
                    unset($leg['patient_ids'], $leg['stop_key']);
                    $leg['sequence'] = count($legs) + 1;
                    $leg['purpose'] = 'NÁVRH - ' . ($leg['is_return']
                        ? 'Návrat na východisko'
                        : $destinations[$id]['purpose']);
                    $leg['source'] = 'proposed_route';
                    $legs[] = $leg;
                }
                $cursor = CarbonImmutable::parse($route[1]['arrival_time'], 'Europe/Bratislava');
                if ($tripIndex + 1 < count($ids)) {
                    $cursor = $cursor->addMinutes(self::BETWEEN_TRIPS_MINUTES);
                }
            }
            $actualDuration = $firstDeparture->diffInSeconds($cursor);
            if ($actualDuration > $limitSeconds) {
                $warnings[] = sprintf('Deň %s trval %.2f hodiny (orientačný denný limit %.1f hodiny).',
                    $date, $actualDuration / 3600, $limitSeconds / 3600);
            }
            $byDay[$date] = $addresses;
            $totalMeters += $meters;
            $totalStops += count($ids);
            $dayTotals[] = [
                'date' => $date,
                'planned_start_time' => $startTimes[$date],
                'departure_time' => $firstDeparture->format('H:i'),
                'arrival_time' => $cursor->format('H:i'),
                'stops' => count($ids),
                'distance_km' => round($meters / 1000, 3),
                'total_time' => sprintf('%02d:%02d:%02d',
                    intdiv((int) $actualDuration, 3600),
                    intdiv((int) $actualDuration % 3600, 60),
                    (int) $actualDuration % 60),
            ];
        }
        if ($dayTotals === []) {
            $this->fail('planning', 'Plán neobsahuje žiadnu uskutočniteľnú trasu.');
        }
        foreach ($destinations as $destination) {
            $limit = $destination['max_visits_per_month'];
            if ($limit !== null && $counts[$destination['id']] > $limit) {
                $this->fail('destinations', 'Prekročený mesačný limit návštev: ' . $this->label($destination));
            }
        }
        if ($startKm !== null && $endKm !== null && $totalMeters > ($endKm - $startKm) * 1000) {
            $this->fail('vehicle', 'Vypočítané trasy prekračujú skutočný rozdiel tachometra.');
        }
        $achieved = round($totalMeters / 1000, 3);
        $deviation = abs($totalMeters - $target) / $target * 100;
        if ($deviation > $tolerance) {
            $warnings[] = sprintf('Cieľ %.3f km, vypočítané %.3f km (odchýlka %.1f %%). ' .
                'Cieľ nebol dosiahnutý; vzdialenosti neboli upravené.', $target / 1000, $achieved, $deviation);
        }
        $overview = [];
        foreach ($destinations as $destination) {
            $id = $destination['id'];
            $overview[] = [
                'name' => $destination['name'],
                'address' => $this->label($destination),
                'visits' => $counts[$id],
                'max_visits_per_month' => $destination['max_visits_per_month'],
            ];
        }
        return [
            'schema_version' => 7,
            'status' => 'planned',
            'source' => 'manual_monthly_json',
            'user_name' => $data['driver']['name'],
            'company_name' => $data['company']['name'] ?? '',
            'company_ico' => $data['company']['ico'] ?? '',
            'car_model' => $data['vehicle']['model'],
            'car_license_plate' => $data['vehicle']['license_plate'],
            'car_vin' => $data['vehicle']['vin'] ?? '',
            'car_consumption_l_per_100km' => $data['vehicle']['consumption_l_per_100km'] ?? null,
            'odometer_actual_start_km' => $startKm,
            'odometer_actual_end_km' => $endKm,
            'odometer_reading_date' => $readingDate,
            'start_date' => $month->startOfMonth()->toDateString(),
            'end_date' => $month->endOfMonth()->toDateString(),
            'last_journey_date' => $dayTotals[array_key_last($dayTotals)]['date'],
            'month' => $month->format('m'),
            'year' => $month->format('Y'),
            'trip_purpose' => 'NÁVRH pracovných ciest',
            'branch_address' => $this->label($base),
            'legs' => $legs,
            'patient_addresses' => $byDay,
            'day_totals' => $dayTotals,
            'coverage' => [
                'schedule_mode' => 'independent_round_trips',
                'available_workdays' => count($dates),
                'scheduled_days' => count($dayTotals),
                'distinct_destinations' => count(array_filter($counts, static fn (int $count): bool => $count > 0)),
                'total_destinations' => count($destinations),
                'all_destinations_covered' => !in_array(0, $counts, true),
                'total_stops' => $totalStops,
                'visits_per_destination' => $overview,
                'candidate_analysis' => null,
            ],
            'month_totals' => [
                'from' => $month->startOfMonth()->toDateString(),
                'to' => $month->endOfMonth()->toDateString(),
                'distance_km' => $achieved,
                'target_km' => $target / 1000,
                'difference_km' => round($achieved - $target / 1000, 3),
                'deviation_percent' => round($deviation, 2),
                'tolerance_percent' => $tolerance,
            ],
            'warnings' => $warnings,
            'calculation_note' => 'NÁVRH: hypotetické dátumy, časy a cesty; nie potvrdené skutočné jazdy. Km podľa cestnej TSP služby.',
        ];
    }

    private function dates(CarbonImmutable $month, array $planning): array
    {
        $weekdays = $planning['weekdays_only'] ?? true;
        $dates = $planning['dates'] ?? [];
        if ($dates === []) {
            for ($day = $month->startOfMonth(); $day <= $month->endOfMonth(); $day = $day->addDay()) {
                if (!$weekdays || $day->isWeekday()) {
                    $dates[] = $day->toDateString();
                }
            }
        }
        foreach (array_merge($dates, $planning['excluded_dates'] ?? []) as $date) {
            if (substr($date, 0, 7) !== $month->format('Y-m')) {
                $this->fail('planning.dates', 'Dátumy musia patriť do mesiaca ' . $month->format('Y-m'));
            }
        }
        $exclude = array_flip($planning['excluded_dates'] ?? []);
        $dates = array_values(array_unique(array_filter($dates,
            static fn (string $date): bool => !isset($exclude[$date]) && (!$weekdays || CarbonImmutable::parse($date)->isWeekday())
        )));
        sort($dates);
        if ($dates === []) {
            $this->fail('planning.dates', 'V plánovanom mesiaci nie sú dostupné dátumy.');
        }
        return $dates;
    }

    private function startTimes(array $dates, array $planning, string $month): array
    {
        if (!isset($planning['departure_time_window'])) {
            return array_fill_keys($dates, $planning['departure_time'] ?? '08:00');
        }
        $window = $planning['departure_time_window'];
        $start = $this->timeToMinutes($window['from']);
        $end = $this->timeToMinutes($window['to']);
        $step = (int) ($window['step_minutes'] ?? 20);
        $slots = intdiv($end - $start, $step) + 1;
        $previous = null;
        $result = [];
        foreach ($dates as $date) {
            $pick = hexdec(substr(hash('sha256', $month . '|' . $date), 0, 7)) % $slots;
            if ($pick === $previous && $slots > 1) {
                $pick = ($pick + 1) % $slots;
            }
            $minutes = $start + $pick * $step;
            $result[$date] = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
            $previous = $pick;
        }
        return $result;
    }

    private function timeToMinutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));
        return 60 * $hours + $minutes;
    }

    private function secondsBetween(string $from, string $to): int
    {
        return (int) CarbonImmutable::parse($from, 'Europe/Bratislava')
            ->diffInSeconds(CarbonImmutable::parse($to, 'Europe/Bratislava'));
    }

    private function location(array $data): array
    {
        return [
            'name' => trim((string) ($data['name'] ?? '')),
            'address' => trim((string) $data['address']),
            'city' => trim((string) ($data['city'] ?? '')),
            'latitude' => (float) $data['latitude'],
            'longitude' => (float) $data['longitude'],
        ];
    }

    private function label(array $location): string
    {
        return trim($location['address'] . ', ' . $location['city'], ', ');
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
