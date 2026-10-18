<?php

namespace App\Support;

final class ClinicalFrequency
{
    public static function normalize(string $frequency): string
    {
        return (string) config("clinical_frequencies.aliases.{$frequency}", $frequency);
    }

    /** @return array<string, int|string>|null */
    public static function definition(string $frequency): ?array
    {
        $normalized = self::normalize($frequency);
        $definition = config("clinical_frequencies.definitions.{$normalized}");

        if (! is_array($definition)) {
            $definition = config("clinical_frequencies.legacy_definitions.{$frequency}");
        }

        return is_array($definition) ? $definition : null;
    }

    /** @return array<int, string> */
    public static function canonicalValues(): array
    {
        return array_keys((array) config('clinical_frequencies.definitions', []));
    }

    /** @return array<int, string> */
    public static function acceptedValues(): array
    {
        return array_values(array_unique([
            ...self::canonicalValues(),
            ...array_keys((array) config('clinical_frequencies.aliases', [])),
            ...array_keys((array) config('clinical_frequencies.legacy_definitions', [])),
        ]));
    }

    public static function occurrencesForPeriod(string $frequency, string $period): ?int
    {
        $definition = self::definition($frequency);
        if ($definition === null) {
            return null;
        }

        return match ([$definition['type'] ?? null, $period]) {
            ['daily', 'day'] => (int) $definition['times_per_day'],
            ['weekly', 'week'] => (int) $definition['times_per_week'],
            ['monthly', 'month'] => (int) $definition['times_per_month'],
            ['once', 'occurrence'] => 1,
            default => null,
        };
    }
}
