<?php

namespace App\Services\Claims;

use Carbon\CarbonImmutable;
use DomainException;

class ClaimInterfaceVersionRegistry
{
    public function forDate(string $type, mixed $serviceDate): string
    {
        $date = CarbonImmutable::parse($serviceDate)->startOfDay();

        return match ($type) {
            '753d' => $date->gte('2026-04-01')
                ? 'F-396/7'
                : throw new DomainException('Pre 753d pred 1. 4. 2026 nie je v projekte overené historické rozhranie.'),
            '793n' => $date->gte('2026-07-01')
                ? 'F-372/10'
                : ($date->gte('2026-01-01')
                    ? 'F-372/9'
                    : throw new DomainException('Pre 793n pred 1. 1. 2026 nie je v projekte overené historické rozhranie.')),
            default => throw new DomainException("Nepodporovaný typ dávky {$type}."),
        };
    }
}
