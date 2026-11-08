<?php

namespace App\Services\Transport;

/**
 * Groups destinations into sensible excursions from the user's base.
 * This only chooses groups; RoutePlanner provides road order, km and times.
 */
final class MonthlyExcursionGrouper
{
    /**
     * @param list<int> $visitIds
     * @param array<int,array{latitude:float,longitude:float}> $destinations
     * @param ?array{latitude:float,longitude:float} $base
     * @return list<list<int>>
     */
    public function group(
        array $visitIds,
        array $destinations,
        int $maxStopsPerExcursion = 6,
        ?array $base = null
    ): array {
        if ($maxStopsPerExcursion < 1) {
            throw new \InvalidArgumentException('Maximum stops per excursion must be positive.');
        }
        $remaining = array_values($visitIds);
        $groups = [];

        while ($remaining !== []) {
            // Begin with the farthest remaining destination, then cluster
            // nearby stops on the same corridor instead of zigzagging home.
            $seedIndex = 0;
            if ($base !== null) {
                $farthest = -1.0;
                foreach ($remaining as $index => $id) {
                    $distance = $this->airDistanceSquared($base, $destinations[$id]);
                    if ($distance > $farthest) {
                        $farthest = $distance;
                        $seedIndex = $index;
                    }
                }
            }
            $group = [array_splice($remaining, $seedIndex, 1)[0]];

            while (count($group) < $maxStopsPerExcursion && $remaining !== []) {
                $last = $destinations[$group[array_key_last($group)]];
                $seed = $destinations[$group[0]];
                $closest = null;
                $bestDistance = INF;
                foreach ($remaining as $index => $id) {
                    // The planner shouldn't submit duplicates; retain a safe
                    // fallback for direct callers with repeated destination IDs.
                    if (in_array($id, $group, true)) {
                        continue;
                    }
                    $destination = $destinations[$id];
                    if ($base !== null && !$this->sameCorridor($base, $seed, $destination)) {
                        continue;
                    }
                    $distance = $this->airDistanceSquared($last, $destination);
                    if ($distance < $bestDistance) {
                        $bestDistance = $distance;
                        $closest = $index;
                    }
                }
                if ($closest === null) {
                    break;
                }
                $group[] = $remaining[$closest];
                array_splice($remaining, $closest, 1);
            }
            $groups[] = $group;
        }

        return $groups;
    }

    private function sameCorridor(array $base, array $seed, array $candidate): bool
    {
        $a = $this->vector($base, $seed);
        $b = $this->vector($base, $candidate);
        $aLength = hypot($a[0], $a[1]);
        $bLength = hypot($b[0], $b[1]);

        // Short local stops can be attached to a longer excursion. For two
        // distant destinations, avoid a huge opposite-direction detour.
        if ($aLength < 0.12 || $bLength < 0.12) {
            return true;
        }
        $cosine = ($a[0] * $b[0] + $a[1] * $b[1]) / ($aLength * $bLength);
        return $cosine > -0.17; // approximately within 100 degrees
    }

    private function vector(array $a, array $b): array
    {
        $averageLatitude = deg2rad(((float) $a['latitude'] + (float) $b['latitude']) / 2);
        return [
            ((float) $b['longitude'] - (float) $a['longitude']) * cos($averageLatitude),
            (float) $b['latitude'] - (float) $a['latitude'],
        ];
    }

    private function airDistanceSquared(array $a, array $b): float
    {
        [$x, $y] = $this->vector($a, $b);
        return $x * $x + $y * $y;
    }
}
