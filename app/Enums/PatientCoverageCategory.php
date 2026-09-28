<?php

namespace App\Enums;

enum PatientCoverageCategory: string
{
    case DOMESTIC = 'domestic';
    case EU = 'eu';
    case NON_EU = 'non_eu';
    case HOMELESS = 'homeless';
    case OTHER = 'other';

    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
