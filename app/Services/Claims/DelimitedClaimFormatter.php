<?php

namespace App\Services\Claims;

use InvalidArgumentException;

class DelimitedClaimFormatter
{
    public function line(array $fields, int $expectedFields): string
    {
        if (count($fields) !== $expectedFields) {
            throw new InvalidArgumentException(
                "Očakáva sa {$expectedFields} polí, prijatých bolo " . count($fields) . '.'
            );
        }

        $values = array_map(function (mixed $value): string {
            $value = (string) ($value ?? '');

            if (str_contains($value, '|') || str_contains($value, "\r") || str_contains($value, "\n")) {
                throw new InvalidArgumentException('Hodnota dávky obsahuje nepovolený oddeľovač alebo nový riadok.');
            }

            return $value;
        }, $fields);

        return implode('|', $values) . '|';
    }

    public function file(array $lines): string
    {
        return implode("\r\n", $lines) . "\r\n";
    }
}
