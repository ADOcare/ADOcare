<?php

namespace App\Services\Transport;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Generates proposed journeys, never persisted as actual visits or official documents. */
class ManualMonthlyDzcPlanner
{
    public function __construct(private RoutePlanner $routes)
    {
    }

    public function plan(array $config): array
    {
        $data = Validator::make($config, [
            'month' => ['required', 'date_format:Y-m'],
            'driver' => ['required', 'array'],
            'driver.name' => ['required', 'string', 'max:200'],
            'company' => ['sometimes', 'array'],
            'company.name' => ['nullable', 'string', 'max:200'],
            'company.ico' => ['nullable', 'string', 'max:20'],
            'vehicle' => ['required', 'array'],
            'vehicle.model' => ['required', 'string', 'max:150'],
            'vehicle.license_plate' => ['required', 'string', 'max:30'],
            'vehicle.vin' => ['nullable', 'string', 'max:50'],
            'vehicle.consumption_l_per_100km' => ['nullable', 'numeric', 'between:0,100'],
            'vehicle.odometer_start_km' => ['nullable', 'integer', 'min:0'],
            'vehicle.odometer_end_km' => ['nullable', 'integer', 'min:0'],
            'vehicle.odometer_reading_date' => ['nullable', 'date_format:Y-m-d'],
            'base' => ['required', 'array'],
            'base.name' => ['nullable', 'string', 'max:150'],
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
            'planning' => ['required', 'array'],
            'planning.strategy' => ['sometimes', 'in:approximate_target'],
            'planning.schedule_mode' => ['sometimes', 'in:target_first,every_workday'],
            'planning.min_repeat_gap_days' => ['sometimes', 'integer', 'between:1,31'],
            'planning.max_daily_hours' => ['sometimes', 'numeric', 'between:1,24'],
            'planning.monthly_km_target' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'planning.tolerance_percent' => ['sometimes', 'numeric', 'between:0,30'],
            'planning.weekdays_only' => ['sometimes', 'boolean'],
            'planning.max_trip_days' => ['sometimes', 'integer', 'between:1,31'],
            'planning.max_stops_per_day' => ['sometimes', 'integer', 'between:1,6'],
            'planning.candidate_limit' => ['sometimes', 'integer', 'between:1,80'],
            'planning.departure_time' => ['sometimes', 'date_format:H:i'],
            'planning.stop_duration_minutes' => ['sometimes', 'integer', 'between:1,180'],
            'planning.dates' => ['sometimes', 'array', 'min:1', 'max:31'],
            'planning.dates.*' => ['date_format:Y-m-d'],
            'planning.excluded_dates' => ['sometimes', 'array', 'max:31'],
            'planning.excluded_dates.*' => ['date_format:Y-m-d'],
            'output' => ['sometimes', 'array'],
            'output.format' => ['sometimes', 'in:pdf,xlsx,both'],
        ])->validate();

        $month = CarbonImmutable::createFromFormat('!Y-m', $data['month'], 'Europe/Bratislava');
        $availableDates = $this->availableDates($month, $data['planning']);
        $readingDate = $data['vehicle']['odometer_reading_date'] ?? $month->endOfMonth()->toDateString();
        if (substr($readingDate, 0, 7) !== $data['month']) {
            $this->fail('vehicle.odometer_reading_date', 'Odčítanie tachometra musí patriť do plánovaného mesiaca.');
        }
        $startKm = $data['vehicle']['odometer_start_km'] ?? null;
        $endKm = isset($data['vehicle']['odometer_end_km']) ? (int) $data['vehicle']['odometer_end_km'] : null;
        if ($startKm !== null && $endKm !== null && $endKm < (int) $startKm) {
            $this->fail('vehicle.odometer_end_km', 'Konečný tachometer nesmie byť nižší než počiatočný.');
        }

        $base = $this->location($data['base']);
        $destinations = [];
        $unique = [];
        foreach ($data['destinations'] as $item) {
            $loc = $this->location($item);
            $key = mb_strtolower($loc['address'] . '|' . $loc['city'], 'UTF-8');
            if (isset($unique[$key])) {
                $this->fail('destinations', 'Adresa je v zozname viac ako raz: ' . $loc['address']);
            }
            $unique[$key] = true;
            if ($this->near($base, $loc)) {
                $this->fail('destinations', 'Cieľ sa nachádza na súradniciach východiska: ' . $loc['address']);
            }
            $loc['id'] = count($destinations);
            $loc['purpose'] = $item['purpose'] ?? 'Pracovná cesta';
            $destinations[] = $loc;
        }

        $targetMeters = (int) round((float) $data['planning']['monthly_km_target'] * 1000);
        $tolerance = (float) ($data['planning']['tolerance_percent'] ?? 5);
        $maxMeters = (int) floor($targetMeters * (1 + max(15, $tolerance) / 100));
        $warnings = [];
        if ($startKm !== null && $endKm !== null) {
            $capacityMeters = ((int) $endKm - (int) $startKm) * 1000;
            $maxMeters = min($maxMeters, $capacityMeters);
            if ($capacityMeters < $targetMeters) {
                $warnings[] = 'Rozdiel tachometra je nižší než mesačný cieľ; plán sa obmedzil na dostupné kilometre.';
            }
        }
        if ($maxMeters <= 0) {
            $this->fail('vehicle', 'Stav tachometra neumožňuje naplánovať žiadne jazdy.');
        }
        $dates = array_values(array_filter($availableDates, fn (string $day) => $day <= $readingDate));
        if ($dates === []) {
            $this->fail('planning.dates', 'Nie je dostupný dátum cesty pred alebo v deň odčítania tachometra.');
        }
        $maxDays = min(count($dates), (int) ($data['planning']['max_trip_days'] ?? 12));
        $maxStops = min(count($destinations), (int) ($data['planning']['max_stops_per_day'] ?? 3));
        $base['terrain_start_time'] = ($data['planning']['departure_time'] ?? '08:00') . ':00';
        $base['per_location_time'] = (int) ($data['planning']['stop_duration_minutes'] ?? 20);
        $everyWorkday = ($data['planning']['schedule_mode'] ?? 'target_first') === 'every_workday';
        if ($everyWorkday) {
            // Every eligible weekday gets a distinct proposed journey. All
            // destinations are assigned before any destination may be repeated.
            $dailyPlans = $this->scheduleEveryWorkday(
                $base, $destinations, $dates, $maxStops, $targetMeters,
                (int) ($data['planning']['min_repeat_gap_days'] ?? 5),
                (float) ($data['planning']['max_daily_hours'] ?? 9),
                (int) ($data['planning']['stop_duration_minutes'] ?? 20)
            );
            if (isset($data['planning']['max_trip_days']) && (int) $data['planning']['max_trip_days'] < count($dates)) {
                $warnings[] = 'V režime every_workday sa max_trip_days ignoruje; použili sa všetky dostupné pracovné dni.';
            }
        } else {
            $templates = $this->templates(
                $base, $destinations, $maxStops,
                max(count($destinations), (int) ($data['planning']['candidate_limit'] ?? 24)),
                $dates[0], $maxMeters
            );
            if ($templates === []) {
                $this->fail('planning.monthly_km_target', 'Ani jedna vypočítaná spiatočná cesta sa nezmestí do dostupného rozsahu kilometrov.');
            }
            $selected = $this->optimize(
                $templates, $targetMeters, $maxMeters, $maxDays,
                $startKm === null || $endKm === null ? null : $endKm - (int) $startKm
            );
            $plannedDates = $this->spreadDates($dates, count($selected));
            $dailyPlans = [];
            foreach ($selected as $index => $templateId) {
                $template = $templates[$templateId];
                $dailyPlans[] = [
                    'date' => $plannedDates[$index],
                    'ids' => $template['ids'],
                    'cached_legs' => $plannedDates[$index] === $dates[0] ? $template['legs'] : null,
                ];
            }
        }

        $legs = [];
        $days = [];
        $byDay = [];
        $stopsById = array_column($destinations, null, 'id');
        $roadMeters = 0;
        $odometerKilometres = 0;
        $visitedIds = [];
        foreach ($dailyPlans as $dailyPlan) {
            $date = $dailyPlan['date'];
            // Dates/times come from the TSP for the specific proposed day.
            $route = $dailyPlan['cached_legs'] ?? $this->routes->plan(
                $base, $this->stops($dailyPlan['ids'], $stopsById), $date
            );
            $dayOdometerKilometres = array_sum(array_map(
                static fn (array $leg): int => (int) round($leg['distance_m'] / 1000),
                $route
            ));
            if ($startKm !== null && $endKm !== null && $odometerKilometres + $dayOdometerKilometres > $endKm - (int) $startKm) {
                if ($everyWorkday) {
                    $this->fail('vehicle.odometer_end_km',
                        'Naplánované pracovné dni a všetky destinácie sa nezmestia do zadaného rozdielu tachometra. ' .
                        'Trasa ' . $date . ' by prekročila dostupné kilometre. Údaje treba zosúladiť, nie vynechať cestu.'
                    );
                }
                $warnings[] = 'Trasa ' . $date . ' bola vynechaná: po prepočte presahuje dostupný rozdiel tachometra.';
                continue;
            }
            $odometerKilometres += $dayOdometerKilometres;
            $first = $route[0];
            $last = $route[array_key_last($route)];
            $dayMeters = 0;
            $addresses = [[
                'type' => 'branch_start', 'address' => $this->label($first['origin']),
                'arrival_time' => $first['departure_time'], 'kilometers' => 0,
            ]];
            foreach ($route as $leg) {
                $dayMeters += (int) $leg['distance_m'];
                $stopId = $leg['is_return'] ? null : (int) substr((string) $leg['stop_key'], 5);
                if ($stopId !== null) {
                    $visitedIds[$stopId] = true;
                }
                $purpose = $stopId === null ? 'Návrat na východisko' : $stopsById[$stopId]['purpose'];
                $addresses[] = [
                    'type' => $leg['is_return'] ? 'branch_end' : 'destination',
                    'address' => $this->label($leg['destination']),
                    'arrival_time' => $leg['arrival_time'], 'kilometers' => round($leg['distance_m'] / 1000, 3),
                ];
                unset($leg['patient_ids'], $leg['stop_key']);
                $leg['purpose'] = 'NÁVRH - ' . $purpose;
                $leg['source'] = 'proposed_route';
                $legs[] = $leg;
            }
            if ($dayMeters <= 0) {
                $this->fail('destinations', 'TSP vrátilo nulové kilometre pre ' . $date . '.');
            }
            $roadMeters += $dayMeters;
            $seconds = CarbonImmutable::parse($first['departure_time'], 'Europe/Bratislava')
                ->diffInSeconds(CarbonImmutable::parse($last['arrival_time'], 'Europe/Bratislava'));
            if ($everyWorkday && $seconds > (float) ($data['planning']['max_daily_hours'] ?? 9) * 3600) {
                $this->fail('planning.max_daily_hours',
                    'Navrhovaná trasa ' . $date . ' trvá ' . round($seconds / 3600, 1) .
                    ' hodín a prekračuje povolenú dĺžku pracovného dňa. Upravte plánovanie.'
                );
            }
            $days[] = [
                'date' => $date,
                'stops' => count($route) - 1,
                'distance_km' => round($dayMeters / 1000, 3),
                'total_time' => sprintf('%02d:%02d:%02d', intdiv((int) $seconds, 3600), intdiv((int) $seconds % 3600, 60), (int) $seconds % 60),
            ];
            $byDay[$date] = $addresses;
        }
        if ($days === []) {
            $this->fail('planning', 'Po prepočte trás nezostala žiadna cesta vhodná pre zadaný tachometer.');
        }
        if ($startKm !== null && $endKm !== null && $roadMeters > ($endKm - (int) $startKm) * 1000) {
            $this->fail('vehicle', 'Výsledná trasa prekročila skutočný rozdiel tachometra. Skúste iný cieľ alebo dátumy.');
        }
        if ($everyWorkday && (count($days) !== count($dates) || count($visitedIds) !== count($destinations))) {
            $this->fail('planning', 'Nepodarilo sa pokryť všetky pracovné dni a všetky destinácie.');
        }
        $achievedKm = round($roadMeters / 1000, 3);
        $relativeDeviation = 100 * abs($roadMeters - $targetMeters) / $targetMeters;
        if ($relativeDeviation > $tolerance) {
            $warnings[] = sprintf('Odchýlka od cieľa je %.1f %% (preferovaná tolerancia %.1f %%).', $relativeDeviation, $tolerance);
            if ($everyWorkday) {
                $warnings[] = 'Prioritou sú všetky dostupné pracovné dni a všetky adresy; cieľové kilometre sú iba orientačné.';
            }
        }

        return [
            'schema_version' => 5,
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
            'last_journey_date' => $days[array_key_last($days)]['date'],
            'month' => $month->format('m'),
            'year' => $month->format('Y'),
            'trip_purpose' => 'Plánované pracovné cesty',
            'branch_address' => $this->label($base),
            'legs' => $legs,
            'patient_addresses' => $byDay, // Historical DZC renderer key, not patients.
            'day_totals' => $days,
            'coverage' => [
                'schedule_mode' => $everyWorkday ? 'every_workday' : 'target_first',
                'available_workdays' => count($dates),
                'scheduled_days' => count($days),
                'distinct_destinations' => count($visitedIds),
                'total_destinations' => count($destinations),
                'all_destinations_covered' => count($visitedIds) === count($destinations),
                'total_stops' => array_sum(array_column($days, 'stops')),
            ],
            'month_totals' => [
                'from' => $month->startOfMonth()->toDateString(),
                'to' => $month->endOfMonth()->toDateString(),
                'distance_km' => $achievedKm,
                'target_km' => (float) $data['planning']['monthly_km_target'],
                'difference_km' => round($achievedKm - (float) $data['planning']['monthly_km_target'], 3),
                'deviation_percent' => round($relativeDeviation, 2),
                'tolerance_percent' => $tolerance,
            ],
            'warnings' => $warnings,
            'calculation_note' => 'NÁVRH: trasy, dátumy a časy nepredstavujú potvrdené skutočné cesty. Vzdialenosti sú z TSP.',
        ];
    }

    private function location(array $location): array
    {
        return [
            'name' => trim((string) ($location['name'] ?? '')),
            'address' => trim((string) $location['address']),
            'city' => trim((string) ($location['city'] ?? '')),
            'latitude' => (float) $location['latitude'],
            'longitude' => (float) $location['longitude'],
        ];
    }

    private function availableDates(CarbonImmutable $month, array $options): array
    {
        $onlyWeekdays = (bool) ($options['weekdays_only'] ?? true);
        $dates = $options['dates'] ?? null;
        if ($dates === null) {
            $dates = [];
            for ($date = $month->startOfMonth(); $date <= $month->endOfMonth(); $date = $date->addDay()) {
                if (!$onlyWeekdays || $date->isWeekday()) {
                    $dates[] = $date->toDateString();
                }
            }
        }
        foreach (array_merge($dates, $options['excluded_dates'] ?? []) as $date) {
            if (substr($date, 0, 7) !== $month->format('Y-m')) {
                $this->fail('planning.dates', 'Dátumy musia byť v mesiaci ' . $month->format('Y-m') . '.');
            }
        }
        $excluded = array_flip($options['excluded_dates'] ?? []);
        $dates = array_values(array_unique(array_filter($dates, function (string $date) use ($excluded, $onlyWeekdays): bool {
            return !isset($excluded[$date]) && (!$onlyWeekdays || CarbonImmutable::parse($date)->isWeekday());
        })));
        sort($dates);
        if ($dates === []) {
            $this->fail('planning.dates', 'Nezostal žiadny dostupný dátum pracovnej cesty.');
        }
        return $dates;
    }

    /**
     * A coverage-first DRAFT scheduler. Distances used for choosing which
     * destinations to combine are geographic estimates ONLY. Every final leg
     * is subsequently measured using RoutePlanner / road-network TSP.
     *
     * A distance target is a preference, never a reason to insert fictitious
     * kilometres or change confirmed odometer readings.
     *
     * @return array<int, array{date: string, ids: array<int, int>, cached_legs: null}>
     */
    private function scheduleEveryWorkday(
        array $base,
        array $destinations,
        array $dates,
        int $maxStops,
        int $targetMeters,
        int $minRepeatGapDays,
        float $maxDailyHours,
        int $stopMinutes
    ): array {
        if (count($destinations) > count($dates) * $maxStops) {
            $this->fail('planning.max_stops_per_day',
                'Nie je možné navštíviť všetky destinácie: zvýšte počet zastávok na deň alebo sprístupnite viac dní.'
            );
        }
        $indexed = array_column($destinations, null, 'id');
        $groups = array_map(static fn (array $stop): array => [$stop['id']], $destinations);
        $estimate = fn (array $ids): float => $this->estimatedLoopMeters($base, $ids, $indexed);
        $totalEstimate = array_sum(array_map($estimate, $groups));
        $daysCount = count($dates);
        $tolerance = 0.05;

        // Merge geographically compatible trips to cover all destinations and
        // free weekday slots for varied repeat visits, if distance requires it.
        // The cheapest feasible merge is chosen by the travel saved.
        while (count($groups) > (int) ceil(count($destinations) / $maxStops)) {
            $best = null;
            $groupCount = count($groups);
            for ($i = 0; $i < $groupCount; $i++) {
                for ($j = $i + 1; $j < $groupCount; $j++) {
                    if (count($groups[$i]) + count($groups[$j]) > $maxStops) {
                        continue;
                    }
                    $merged = [...$groups[$i], ...$groups[$j]];
                    $mergedCost = $estimate($merged);
                    // Conservative proxy for working-day length. Final days
                    // are validated against TSP-provided arrival timestamps.
                    if ($mergedCost / 65000 + count($merged) * $stopMinutes / 60 > $maxDailyHours) {
                        continue;
                    }
                    $saving = $estimate($groups[$i]) + $estimate($groups[$j]) - $mergedCost;
                    if ($best === null || $saving > $best['saving']) {
                        $best = ['i' => $i, 'j' => $j, 'saving' => $saving, 'new_cost' => $mergedCost];
                    }
                }
            }
            if ($best === null) {
                break;
            }
            // We must merge if there are more distinct groups than days.
            // Otherwise, stop once the approximate distance is in the target
            // neighborhood, leaving visits distributed across more days.
            if ($groupCount <= $daysCount && $totalEstimate <= $targetMeters * (1 + $tolerance)) {
                break;
            }
            $a = $best['i'];
            $b = $best['j'];
            $oldCost = $estimate($groups[$a]) + $estimate($groups[$b]);
            $groups[$a] = [...$groups[$a], ...$groups[$b]];
            array_splice($groups, $b, 1);
            $totalEstimate += $best['new_cost'] - $oldCost;
        }
        if (count($groups) > $daysCount) {
            $this->fail('planning', 'Destinácie nebolo možné zoskupiť do dostupných pracovných dní.');
        }
        $groups = $this->interleaveGeographicGroups($groups, $indexed);
        $daily = array_fill_keys($dates, []);
        $groupCount = count($groups);
        // Distribute primary visits across the calendar, then fill only the
        // remaining dates, with repeats separated by the configured gap.
        foreach ($groups as $index => $ids) {
            $dateIndex = (int) floor(($index + 0.5) * $daysCount / $groupCount);
            $daily[$dates[$dateIndex]] = $ids;
        }
        $assignedCost = $totalEstimate;
        $emptyDates = array_keys(array_filter($daily, static fn (array $ids): bool => $ids === []));
        foreach ($emptyDates as $position => $date) {
            $remaining = count($emptyDates) - $position;
            $idealMeters = max(0, ($targetMeters - $assignedCost) / $remaining);
            $candidates = [];
            foreach ($indexed as $id => $stop) {
                $gap = $this->repeatDistanceDays($date, $id, $daily);
                $cost = $estimate([$id]);
                $frequency = count(array_filter($daily, static fn (array $ids): bool => in_array($id, $ids, true)));
                $candidates[] = [
                    'id' => $id,
                    'cost' => $cost,
                    'gap' => $gap,
                    // Penalize excessive repetition. Prefer realistic road
                    // distance to the remaining target, but not at the expense
                    // of revisiting the same destination every other day.
                    'score' => abs($cost - $idealMeters) + max(0, $frequency - 1) * 14000,
                ];
            }
            $eligible = array_values(array_filter($candidates,
                static fn (array $candidate): bool => $candidate['gap'] >= $minRepeatGapDays
            ));
            if ($eligible === []) {
                // With too few addresses this spacing may be impossible.
                // Choose the longest possible gap, not a fabricated stop.
                $longestGap = max(array_column($candidates, 'gap'));
                $eligible = array_values(array_filter($candidates,
                    static fn (array $candidate): bool => $candidate['gap'] === $longestGap
                ));
            }
            usort($eligible, static fn (array $a, array $b): int => ($a['score'] <=> $b['score']) ?: ($a['id'] <=> $b['id']));
            $selected = $eligible[0];
            $daily[$date] = [$selected['id']];
            $assignedCost += $selected['cost'];
        }
        $result = [];
        foreach ($daily as $date => $ids) {
            if ($ids === []) {
                $this->fail('planning', 'Interná chyba: deň bez zastávky ' . $date . '.');
            }
            $result[] = ['date' => $date, 'ids' => $ids, 'cached_legs' => null];
        }
        return $result;
    }

    /** Minimum difference in calendar days to any existing use of this stop. */
    private function repeatDistanceDays(string $date, int $id, array $daily): int
    {
        $gap = 999;
        $current = CarbonImmutable::parse($date, 'Europe/Bratislava');
        foreach ($daily as $assignedDate => $ids) {
            if (in_array($id, $ids, true)) {
                $gap = min($gap, abs((int) $current->diffInDays(
                    CarbonImmutable::parse($assignedDate, 'Europe/Bratislava'), false
                )));
            }
        }
        return $gap;
    }

    /** Alternates geographic areas to avoid blocks of near-identical days. */
    private function interleaveGeographicGroups(array $groups, array $indexed): array
    {
        $buckets = [];
        foreach ($groups as $ids) {
            $first = $indexed[$ids[0]];
            $key = mb_strtolower($first['city'] ?: $first['address'], 'UTF-8');
            $buckets[$key][] = $ids;
        }
        $ordered = [];
        $previous = null;
        while ($buckets !== []) {
            $keys = array_keys($buckets);
            usort($keys, static fn (string $a, string $b): int =>
                (count($buckets[$b]) <=> count($buckets[$a])) ?: strcmp($a, $b)
            );
            $choice = $keys[0];
            foreach ($keys as $candidate) {
                if ($candidate !== $previous) {
                    $choice = $candidate;
                    break;
                }
            }
            $ordered[] = array_shift($buckets[$choice]);
            if ($buckets[$choice] === []) {
                unset($buckets[$choice]);
            }
            $previous = $choice;
        }
        return $ordered;
    }

    /** Estimate for grouping only; TSP road metres are used in actual output. */
    private function estimatedLoopMeters(array $base, array $ids, array $indexed): float
    {
        $remaining = $ids;
        $position = $base;
        $meters = 0.0;
        while ($remaining !== []) {
            usort($remaining, fn (int $a, int $b): int =>
                $this->squareDistance($position, $indexed[$a]) <=>
                $this->squareDistance($position, $indexed[$b])
            );
            $next = array_shift($remaining);
            $meters += sqrt($this->squareDistance($position, $indexed[$next]));
            $position = $indexed[$next];
        }
        $meters += sqrt($this->squareDistance($position, $base));
        return $meters * 1.35;
    }

    private function templates(array $base, array $destinations, int $maxStops, int $limit, string $date, int $capMeters): array
    {
        $combinations = [];
        $seen = [];
        $add = function (array $ids) use (&$combinations, &$seen, $limit): void {
            sort($ids, SORT_NUMERIC);
            $key = implode(':', $ids);
            if (isset($seen[$key]) || count($combinations) >= $limit) {
                return;
            }
            $seen[$key] = true;
            $combinations[] = $ids;
        };
        $count = count($destinations);
        for ($i = 0; $i < $count; $i++) {
            $add([$i]);
        }
        // Build short, geographically coherent combinations; the TSP still
        // decides the actual visit order using road distances, not Haversine.
        for ($size = 2; $size <= $maxStops; $size++) {
            for ($i = 0; $i < $count; $i++) {
                $neighbors = array_keys($destinations);
                usort($neighbors, function ($a, $b) use ($destinations, $i): int {
                    return $this->squareDistance($destinations[$i], $destinations[$a])
                        <=> $this->squareDistance($destinations[$i], $destinations[$b]);
                });
                $add(array_slice($neighbors, 0, $size));
            }
        }
        $result = [];
        foreach ($combinations as $ids) {
            $route = $this->routes->plan($base, $this->stops($ids, array_column($destinations, null, 'id')), $date);
            $meters = (int) array_sum(array_column($route, 'distance_m'));
            if ($meters <= 0) {
                $this->fail('destinations', 'TSP vrátilo nulovú alebo neplatnú vzdialenosť.');
            }
            if ($meters <= $capMeters) {
                $result[] = [
                    'ids' => $ids, 'meters' => $meters, 'legs' => $route,
                    'odometer_km' => array_sum(array_map(
                        static fn (array $leg): int => (int) round($leg['distance_m'] / 1000),
                        $route
                    )),
                ];
            }
        }
        return $result;
    }

    private function stops(array $ids, array $locations): array
    {
        return array_map(static function (int $id) use ($locations): array {
            $loc = $locations[$id];
            return [
                'key' => 'dest_' . $id, 'address' => $loc['address'], 'city' => $loc['city'],
                'longitude' => $loc['longitude'], 'latitude' => $loc['latitude'],
                'patient_ids' => ['planned-stop'], // Count only, never persisted.
            ];
        }, $ids);
    }

    private function optimize(array $templates, int $target, int $cap, int $maxDays, ?int $odometerCapacity): array
    {
        // Quantized bounded subset-sum with a fixed state cap. All final distances
        // are recomputed from exact TSP metres; quantization affects search only.
        $bucket = max(250, (int) ceil($target / 1600));
        $states = [['meters' => 0, 'rounded_km' => 0, 'selection' => [], 'coverage' => []]];
        $best = null;
        for ($count = 1; $count <= $maxDays; $count++) {
            $next = [];
            foreach ($states as $state) {
                foreach ($templates as $index => $template) {
                    $sum = $state['meters'] + $template['meters'];
                    $rounded = $state['rounded_km'] + $template['odometer_km'];
                    if ($sum > $cap || ($odometerCapacity !== null && $rounded > $odometerCapacity)) {
                        continue;
                    }
                    $coverage = $state['coverage'];
                    foreach ($template['ids'] as $id) {
                        $coverage[$id] = true;
                    }
                    $candidate = [
                        'meters' => $sum,
                        'rounded_km' => $rounded,
                        'selection' => [...$state['selection'], $index],
                        'coverage' => $coverage,
                    ];
                    if ($this->better($candidate, $best, $target)) {
                        $best = $candidate;
                    }
                    $key = (int) round($sum / $bucket);
                    if ($this->better($candidate, $next[$key] ?? null, $target)) {
                        $next[$key] = $candidate;
                    }
                }
            }
            if ($next === []) {
                break;
            }
            $states = array_values($next);
            if (count($states) > 2000) {
                usort($states, fn ($a, $b) => $this->ranking($a, $target) <=> $this->ranking($b, $target));
                $states = array_slice($states, 0, 2000);
            }
        }
        if ($best === null) {
            $this->fail('planning', 'Pre zadaný cieľ sa nenašla žiadna uskutočniteľná spiatočná trasa.');
        }
        return $best['selection'];
    }

    private function ranking(array $state, int $target): int
    {
        return abs($target - $state['meters']);
    }

    private function better(array $candidate, ?array $previous, int $target): bool
    {
        if ($previous === null) {
            return true;
        }
        $diff = abs($candidate['meters'] - $target) <=> abs($previous['meters'] - $target);
        if ($diff !== 0) {
            return $diff < 0;
        }
        $coverage = count($candidate['coverage']) <=> count($previous['coverage']);
        if ($coverage !== 0) {
            return $coverage > 0;
        }
        return count($candidate['selection']) < count($previous['selection']);
    }

    private function spreadDates(array $dates, int $count): array
    {
        $result = [];
        for ($i = 0; $i < $count; $i++) {
            $result[] = $dates[min(count($dates) - 1, (int) floor(($i + 0.5) * count($dates) / $count))];
        }
        return $result;
    }

    private function squareDistance(array $a, array $b): float
    {
        $latDelta = ($a['latitude'] - $b['latitude']) * 111000;
        $lonDelta = ($a['longitude'] - $b['longitude']) * 111000 * cos(deg2rad($a['latitude']));
        return $latDelta * $latDelta + $lonDelta * $lonDelta;
    }

    private function near(array $a, array $b): bool
    {
        return abs($a['latitude'] - $b['latitude']) < 0.000001
            && abs($a['longitude'] - $b['longitude']) < 0.000001;
    }

    private function label(array $location): string
    {
        return trim($location['address'] . ', ' . ($location['city'] ?? ''), ', ');
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
