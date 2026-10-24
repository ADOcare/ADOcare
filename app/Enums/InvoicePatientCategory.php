<?php

namespace App\Enums;

enum InvoicePatientCategory: string
{
    case DOMESTIC = 'domestic';
    case EU = 'eu';
    case SPECIAL = 'special';

    public function label(): string
    {
        return match ($this) {
            self::DOMESTIC => 'Tuzemskí poistenci',
            self::EU => 'Poistenci EÚ/EHP/Švajčiarska',
            self::SPECIAL => 'Ostatní poistenci',
        };
    }

    /** @return list<string> */
    public function batchSubtypes(): array
    {
        return match ($this) {
            self::DOMESTIC => ['N', 'O', 'A'],
            self::EU => ['E', 'F', 'G'],
            self::SPECIAL => ['I', 'J', 'K'],
        };
    }

    public function purpose(string $invoiceType): string
    {
        $service = match ($invoiceType) {
            'procedures' => 'Ošetrovateľská starostlivosť',
            'transport' => 'Doprava',
            default => 'Faktúra',
        };

        return $service.' – '.$this->label();
    }
}