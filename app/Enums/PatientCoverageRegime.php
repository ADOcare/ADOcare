<?php

namespace App\Enums;

enum PatientCoverageRegime: string
{
    case DOMESTIC = 'domestic';
    case EU = 'eu';
    case SPECIAL = 'special';
    case UNCLASSIFIED = 'unclassified';

    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
