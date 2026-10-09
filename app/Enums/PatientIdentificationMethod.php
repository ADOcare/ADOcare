<?php

namespace App\Enums;

enum PatientIdentificationMethod: string
{
    case SLOVAK_IDENTIFIER = 'slovak_identifier';
    case FOREIGN_TRIAD = 'foreign_triad';

    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
