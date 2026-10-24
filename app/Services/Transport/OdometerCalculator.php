<?php

namespace App\Services\Transport;

use InvalidArgumentException;

class OdometerCalculator
{
    public function calculate(array $legs, int $endKm, ?int $previousKm = null): array
    {
        if ($endKm < 0) {
            throw new InvalidArgumentException(
                'Zadajte nezáporný konečný stav tachometra.'
            );
        }

        $cursor = $endKm;
        $result = $legs;

        for ($index = count($result) - 1; $index >= 0; $index--) {
            $meters = $result[$index]['distance_m'] ?? null;

            if (
                !is_numeric($meters)
                || (float) $meters < 0
                || !is_finite((float) $meters)
            ) {
                throw new InvalidArgumentException(
                    'Úsek nemá platnú vypočítanú vzdialenosť.'
                );
            }

            $distanceKm = (int) round((float) $meters / 1000);

            $result[$index]['odometer_end_km'] = $cursor;

            $result[$index]['odometer_end_source'] =
                $index === count($result) - 1
                    ? 'user_entered'
                    : 'calculated';

            $cursor -= $distanceKm;

            $result[$index]['odometer_start_km'] = $cursor;
        }

        if ($cursor < 0) {
            throw new InvalidArgumentException(
                'Konečný stav je menší než súčet vypočítaných kilometrov.'
            );
        }

        if ($previousKm !== null && $endKm < $previousKm) {
            throw new InvalidArgumentException(
                'Konečný stav je nižší než predchádzajúci zadaný stav vozidla.'
            );
        }

        return [
            'legs' => $result,
            'start_km' => $cursor,
            'end_km' => $endKm,
            'previous_km' => $previousKm,
            'difference_km' => $previousKm === null
                ? null
                : $cursor - $previousKm,
        ];
    }
}