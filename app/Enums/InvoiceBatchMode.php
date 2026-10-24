<?php

namespace App\Enums;

enum InvoiceBatchMode: string
{
    case COMBINED = 'combined';
    case REGULAR = 'regular';
    case CORRECTIVE = 'corrective';
    case ADDITIVE = 'additive';

    public function label(): string
    {
        return match ($this) {
            self::COMBINED => 'Súhrnná faktúra',
            self::REGULAR => 'Riadna faktúra',
            self::CORRECTIVE => 'Opravná faktúra',
            self::ADDITIVE => 'Aditívna faktúra',
        };
    }

    /** @return list<string> */
    public function batchSubtypes(InvoicePatientCategory $patientCategory): array
    {
        $subtypes = $patientCategory->batchSubtypes();

        return match ($this) {
            self::COMBINED => $subtypes,
            self::REGULAR => [$subtypes[0]],
            self::CORRECTIVE => [$subtypes[1]],
            self::ADDITIVE => [$subtypes[2]],
        };
    }
}