<?php

namespace App\Services\Transport;

/**
 * Selects hypothetical out-and-back trips against a kilometre goal.
 * All distances and travel durations must come from a routing service.
 * Does not create or validate a record of journeys actually undertaken.
 */
final class MonthlyMileageOptimizer
{
    /**
     * @param array<int,array{distance_m:int,duration_s:int,max_visits_per_month:?int}> $options
     * @param list<string> $dates
     * @return array<string,list<int>> Destination IDs indexed by date.
     */
    public function optimize(
        array $options,
        array $dates,
        int $targetMeters,
        int $dailyVisitLimit = 6,
        int $dailyTimeLimitSeconds = 43200,
        int $betweenTripsSeconds = 600,
        ?int $maxTotalMeters = null,
        int $seed = 202602
    ): array {
        if ($options === [] || $dates === [] || $targetMeters <= 0 || $dailyVisitLimit < 1) {
            throw new \InvalidArgumentException('Invalid monthly routing inputs.');
        }
        $dayCount = count($dates);
        $schedule = array_fill(0, $dayCount, []);
        $dayMeters = array_fill(0, $dayCount, 0);
        $daySeconds = array_fill(0, $dayCount, 0);
        $counts = array_fill(0, count($options), 0);
        $total = 0;
        $maxTotalMeters ??= (int) round($targetMeters * 1.15);
        $monthlyAverage = $targetMeters / $dayCount;

        // For a substantial monthly goal, first reserve one hypothetical trip
        // per working day. This preserves the user's every-workday preference
        // without requiring every destination to be visited.
        $minTripMeters = min(array_column($options, 'distance_m'));
        $preferEveryDay = $targetMeters >= $dayCount * $minTripMeters;
        if ($preferEveryDay) {
            foreach ($dates as $day => $date) {
                $pick = null;
                $pickScore = INF;
                foreach ($options as $id => $option) {
                    if (!$this->eligible($option, $counts[$id]) ||
                        $option['duration_s'] > $dailyTimeLimitSeconds ||
                        $total + $option['distance_m'] > $maxTotalMeters) {
                        continue;
                    }
                    $score = abs($option['distance_m'] - $monthlyAverage)
                        + 150 * $counts[$id];
                    if ($score < $pickScore) {
                        $pick = $id;
                        $pickScore = $score;
                    }
                }
                if ($pick === null) {
                    break;
                }
                $schedule[$day][] = $pick;
                $counts[$pick]++;
                $dayMeters[$day] = $options[$pick]['distance_m'];
                $daySeconds[$day] = $options[$pick]['duration_s'];
                $total += $options[$pick]['distance_m'];
            }
        }
        $preserveScheduledDays = $preferEveryDay && !in_array([], $schedule, true);

        // Greedy construction: prioritize mileage while spreading duty across
        // available workdays. A destination may be repeated on the same day.
        for ($iteration = 0; $iteration < $dayCount * $dailyVisitLimit; $iteration++) {
            $best = null;
            foreach ($schedule as $day => $ids) {
                if (count($ids) >= $dailyVisitLimit) {
                    continue;
                }
                foreach ($options as $id => $option) {
                    if (!$this->eligible($option, $counts[$id])) {
                        continue;
                    }
                    $nextKm = $total + $option['distance_m'];
                    if ($nextKm > $maxTotalMeters) {
                        continue;
                    }
                    $extra = $option['duration_s'] + ($ids !== [] ? $betweenTripsSeconds : 0);
                    if ($daySeconds[$day] + $extra > $dailyTimeLimitSeconds) {
                        continue;
                    }
                    $improvement = abs($targetMeters - $total) - abs($targetMeters - $nextKm);
                    if ($improvement <= 0) {
                        continue;
                    }
                    // Large unbalanced days have a gentle penalty; distance
                    // accuracy always dominates when choosing the final result.
                    $imbalance = max(0, $dayMeters[$day] + $option['distance_m'] - $monthlyAverage);
                    $score = $improvement - 0.25 * $imbalance - 120 * $counts[$id]
                        - 120 * count($ids);
                    if ($best === null || $score > $best['score']) {
                        $best = ['id' => $id, 'day' => $day, 'extra' => $extra, 'score' => $score];
                    }
                }
            }
            if ($best === null) {
                break;
            }
            $id = $best['id'];
            $day = $best['day'];
            $schedule[$day][] = $id;
            $counts[$id]++;
            $dayMeters[$day] += $options[$id]['distance_m'];
            $daySeconds[$day] += $best['extra'];
            $total += $options[$id]['distance_m'];
        }

        // Deterministic one-trip replacements can correct the last few km
        // without scheduling an entire extra circuit.
        for ($pass = 0; $pass < 30; $pass++) {
            $best = null;
            $error = abs($total - $targetMeters);
            foreach ($schedule as $day => $ids) {
                foreach ($ids as $slot => $oldId) {
                    foreach ($options as $newId => $option) {
                        if ($oldId === $newId || !$this->eligible($option, $counts[$newId])) {
                            continue;
                        }
                        $newTotal = $total - $options[$oldId]['distance_m'] + $option['distance_m'];
                        $newSeconds = $daySeconds[$day] - $options[$oldId]['duration_s'] + $option['duration_s'];
                        if ($newTotal > $maxTotalMeters || $newSeconds > $dailyTimeLimitSeconds) {
                            continue;
                        }
                        $newError = abs($newTotal - $targetMeters);
                        if ($newError < $error) {
                            $error = $newError;
                            $best = [$day, $slot, $oldId, $newId];
                        }
                    }
                }
            }
            if ($best === null) {
                break;
            }
            [$day, $slot, $oldId, $newId] = $best;
            $schedule[$day][$slot] = $newId;
            $counts[$oldId]--;
            $counts[$newId]++;
            $dayMeters[$day] += $options[$newId]['distance_m'] - $options[$oldId]['distance_m'];
            $daySeconds[$day] += $options[$newId]['duration_s'] - $options[$oldId]['duration_s'];
            $total += $options[$newId]['distance_m'] - $options[$oldId]['distance_m'];
        }

        // A seeded local search explores two-trip changes, which are often
        // required to hit a narrow kilometre tolerance. Best valid solution
        // is retained; less-good states are never emitted merely to fit goal.
        $bestSchedule = $schedule;
        $bestError = abs($total - $targetMeters);
        $random = $seed & 0x7fffffff;
        $rand = static function (int $bound) use (&$random): int {
            $random = (int) (($random * 1103515245 + 12345) & 0x7fffffff);
            return $random % $bound;
        };
        $iterations = min(24000, max(6000, $dayCount * $dailyVisitLimit * 150));
        for ($iter = 0; $iter < $iterations; $iter++) {
            $day = $rand($dayCount);
            $size = count($schedule[$day]);
            $action = $rand(3); // add, remove, substitute
            if ($action === 0 && $size >= $dailyVisitLimit) {
                continue;
            }
            if ($action !== 0 && $size === 0) {
                continue;
            }
            if ($action === 1 && $size === 1 && $preserveScheduledDays) {
                continue;
            }
            $slot = $size > 0 ? $rand($size) : null;
            $newId = $rand(count($options));
            $oldId = $action === 0 ? null : $schedule[$day][$slot];
            if ($action === 2 && $newId === $oldId) {
                continue;
            }
            if ($action !== 1 && !$this->eligible($options[$newId], $counts[$newId])) {
                continue;
            }
            $newMeters = $total + ($action === 1 ? 0 : $options[$newId]['distance_m'])
                - ($oldId === null ? 0 : $options[$oldId]['distance_m']);
            $newSeconds = $daySeconds[$day]
                + ($action === 1 ? 0 : $options[$newId]['duration_s'])
                - ($oldId === null ? 0 : $options[$oldId]['duration_s']);
            if ($action === 0 && $size > 0) {
                $newSeconds += $betweenTripsSeconds;
            } elseif ($action === 1 && $size > 1) {
                $newSeconds -= $betweenTripsSeconds;
            }
            if ($newMeters < 0 || $newMeters > $maxTotalMeters || $newSeconds > $dailyTimeLimitSeconds) {
                continue;
            }
            $oldError = abs($total - $targetMeters);
            $newError = abs($newMeters - $targetMeters);
            $temperature = max(150.0, 15000.0 * (1 - $iter / $iterations));
            if ($newError > $oldError && $rand(1000000) / 1000000 > exp(($oldError - $newError) / $temperature)) {
                continue;
            }
            if ($action === 0) {
                $schedule[$day][] = $newId;
                $counts[$newId]++;
            } elseif ($action === 1) {
                array_splice($schedule[$day], $slot, 1);
                $counts[$oldId]--;
            } else {
                $schedule[$day][$slot] = $newId;
                $counts[$oldId]--;
                $counts[$newId]++;
            }
            $total = $newMeters;
            $daySeconds[$day] = $newSeconds;
            if ($newError < $bestError) {
                $bestError = $newError;
                $bestSchedule = $schedule;
                if ($bestError <= 20) {
                    break;
                }
            }
        }
        $result = [];
        foreach ($dates as $index => $date) {
            $result[$date] = array_values($bestSchedule[$index]);
        }
        return $result;
    }

    private function eligible(array $option, int $count): bool
    {
        $limit = $option['max_visits_per_month'] ?? null;
        return $limit === null || $count < $limit;
    }
}
