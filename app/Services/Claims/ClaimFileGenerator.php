<?php

namespace App\Services\Claims;

class ClaimFileGenerator
{
    public function __construct(private DelimitedClaimFormatter $formatter)
    {
    }

    /**
     * @param array<int, array{fields: array, count: int}> $records
     */
    public function generate(array $records): string
    {
        $lines = array_map(
            fn (array $record) => $this->formatter->line($record['fields'], $record['count']),
            $records,
        );

        return $this->formatter->file($lines);
    }
}
