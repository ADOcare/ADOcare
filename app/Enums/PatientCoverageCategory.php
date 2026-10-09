<?php

namespace App\Enums;

enum PatientCoverageCategory: string
{
    case DOMESTIC = 'domestic';
    case EU = 'eu';
    case SPECIAL = 'special';

    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
