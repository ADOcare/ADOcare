<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class PatientPersonalNumberValidator
{
    /**
     * Normalize and validate a Slovak/Czech personal number.
     *
     * @param  string|null  $value
     * @return array{valid: bool, normalized: ?string, reason: ?string}
     */
    public function validate(?string $value): array
    {
        $normalized = $this->normalize($value);
        if ($normalized === null) {
            return [
                'valid' => false,
                'normalized' => null,
                'reason' => trim((string) $value) === '' ? 'empty' : 'invalid_format',
            ];
        }

        $length = strlen($normalized);
        if (! in_array($length, [9, 10], true)) {
            return ['valid' => false, 'normalized' => $normalized, 'reason' => 'invalid_length'];
        }

        $yearPart = (int) substr($normalized, 0, 2);
        $encodedMonth = (int) substr($normalized, 2, 2);
        $day = (int) substr($normalized, 4, 2);
        $monthOffset = match (true) {
            $encodedMonth >= 1 && $encodedMonth <= 12 => 0,
            $encodedMonth >= 21 && $encodedMonth <= 32 => 20,
            $encodedMonth >= 51 && $encodedMonth <= 62 => 50,
            $encodedMonth >= 71 && $encodedMonth <= 82 => 70,
            default => null,
        };

        if ($monthOffset === null) {
            return ['valid' => false, 'normalized' => $normalized, 'reason' => 'invalid_month'];
        }

        if ($length === 9 && ($yearPart >= 54 || in_array($monthOffset, [20, 70], true))) {
            return ['valid' => false, 'normalized' => $normalized, 'reason' => 'invalid_length'];
        }

        $year = $length === 9
            ? 1900 + $yearPart
            : ($monthOffset === 20 || $monthOffset === 70 || $yearPart < 54
                ? 2000 + $yearPart
                : 1900 + $yearPart);
        $month = $encodedMonth - $monthOffset;

        if (in_array($monthOffset, [20, 70], true) && $year < 2004) {
            return ['valid' => false, 'normalized' => $normalized, 'reason' => 'invalid_month'];
        }

        if (! checkdate($month, $day, $year) || CarbonImmutable::create($year, $month, $day)->startOfDay()->gt(CarbonImmutable::today())) {
            return ['valid' => false, 'normalized' => $normalized, 'reason' => 'invalid_date'];
        }

        if ($length === 10 && $year >= 1985 && (int) $normalized % 11 !== 0) {
            return ['valid' => false, 'normalized' => $normalized, 'reason' => 'invalid_checksum'];
        }

        return ['valid' => true, 'normalized' => $normalized, 'reason' => null];
    }

    /**
     * Remove supported separators without modifying the digits.
     *
     * @param  string|null  $value
     */
    public function normalize(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $normalized = preg_replace('/[\s\/-]+/u', '', $value);
        if (! is_string($normalized) || preg_match('/^\d+$/D', $normalized) !== 1) {
            return null;
        }

        return $normalized;
    }
}
