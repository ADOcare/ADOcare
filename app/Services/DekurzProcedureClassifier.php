<?php

namespace App\Services;

class DekurzProcedureClassifier
{
    /**
     * @return list<string>
     */
    public function documentableCodes(): array
    {
        return array_values(array_unique(array_map(
            static fn ($code): string => strtoupper(trim((string) $code)),
            config('dekurz.documentable_procedure_codes', []),
        )));
    }

    public function isDocumentable(?string $code): bool
    {
        return in_array(strtoupper(trim((string) $code)), $this->documentableCodes(), true);
    }
}