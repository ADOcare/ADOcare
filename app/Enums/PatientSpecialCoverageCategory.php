<?php

namespace App\Enums;

enum PatientSpecialCoverageCategory: string
{
    case HOMELESS = 'homeless';
    case NON_EU_FOREIGNER = 'non_eu_foreigner';
    case STATUTORY_ENTITLEMENT_9_3 = 'statutory_entitlement_9_3';

    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
