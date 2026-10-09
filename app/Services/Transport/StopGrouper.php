<?php

namespace App\Services\Transport;

use InvalidArgumentException;

class StopGrouper
{
    public function group(array $visits): array
    {
        usort($visits, fn (array $a, array $b) => $a['patient_id'] <=> $b['patient_id']);
        $stops = [];
        foreach ($visits as $visit) {
            foreach (['latitude', 'longitude'] as $coordinate) {
                if (!isset($visit[$coordinate]) || !is_numeric($visit[$coordinate]) || !is_finite((float) $visit[$coordinate])) {
                    throw new InvalidArgumentException('Návšteva nemá platné súradnice: pacient #' . $visit['patient_id']);
                }
            }
            if (abs((float) $visit['latitude']) > 90 || abs((float) $visit['longitude']) > 180) {
                throw new InvalidArgumentException('Súradnice návštevy sú mimo rozsahu.');
            }
            $address = trim((string) ($visit['address'] ?? ''));
            $city = trim((string) ($visit['city'] ?? ''));
            if ($address === '' || $city === '') {
                throw new InvalidArgumentException('Návšteva nemá úplnú adresu: pacient #' . $visit['patient_id']);
            }
            // A proximity radius alone cannot establish that two buildings are one stop.
            $key = hash('sha256', $this->normalize($city) . '|' . $this->normalize($address));
            if (!isset($stops[$key])) {
                $stops[$key] = [
                    'key' => $key,
                    'address' => $address,
                    'city' => $city,
                    'latitude' => (float) $visit['latitude'],
                    'longitude' => (float) $visit['longitude'],
                    'patient_ids' => [],
                ];
            }
            $stops[$key]['patient_ids'][(int) $visit['patient_id']] = (int) $visit['patient_id'];
        }
        ksort($stops);
        foreach ($stops as &$stop) {
            $stop['patient_ids'] = array_values($stop['patient_ids']);
            sort($stop['patient_ids']);
        }
        unset($stop);
        return array_values($stops);
    }

    private function normalize(string $value): string
    {
        return preg_replace('/\s+/u', ' ', mb_strtolower(trim($value), 'UTF-8'));
    }
}
