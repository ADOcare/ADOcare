<?php

namespace App\Services\Transport;

use InvalidArgumentException;

class OdometerCalculator
{
    public function calculate(array $legs, float $endKm, ?float $previousKm = null): array
    {
        if (!is_finite($endKm) || $endKm < 0) {
            throw new InvalidArgumentException('Zadajte nezáporný konečný stav tachometra.');
        }
        $cursor = (int) round($endKm * 1000);
        $result = $legs;
        for ($index = count($result) - 1; $index >= 0; $index--) {
            $meters = $result[$index]['distance_m'] ?? null;
            if (!is_numeric($meters) || (float) $meters < 0 || !is_finite((float) $meters)) {
                throw new InvalidArgumentException('Úsek nemá platnú vypočítanú vzdialenosť.');
            }
            $result[$index]['odometer_end_km'] = $cursor / 1000;
            $result[$index]['odometer_end_source'] = $index === count($result) - 1 ? 'user_entered' : 'calculated';
            $cursor -= (int) round((float) $meters);
            $result[$index]['odometer_start_km'] = $cursor / 1000;
        }
        if ($cursor < 0) {
            throw new InvalidArgumentException('Konečný stav je menší než súčet vypočítaných kilometrov.');
        }
        if ($previousKm !== null && $endKm < $previousKm) {
            throw new InvalidArgumentException('Konečný stav je nižší než predchádzajúci zadaný stav vozidla.');
        }
        return [
            'legs' => $result,
            'start_km' => $cursor / 1000,
            'end_km' => $endKm,
            'previous_km' => $previousKm,
            'difference_km' => $previousKm === null ? null : round($cursor / 1000 - $previousKm, 3),
        ];
    }
}
