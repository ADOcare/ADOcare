<?php

namespace App\Services\Claims;

use App\Enums\PatientCoverageCategory;
use App\Enums\PatientIdentificationMethod;

class InsuredClaimResolver
{
    private const CHARACTER_BY_CATEGORY = [
        PatientCoverageCategory::DOMESTIC->value => ['N' => 'N', 'O' => 'O', 'A' => 'A'],
        PatientCoverageCategory::EU->value => ['N' => 'E', 'O' => 'F', 'A' => 'G'],
        PatientCoverageCategory::SPECIAL->value => ['N' => 'I', 'O' => 'J', 'A' => 'K'],
    ];

    public function resolve(object|array $source, string $operation): ResolvedInsured
    {
        $value = fn (string $key) => is_array($source)
            ? ($source[$key] ?? null)
            : ($source->{$key} ?? null);

        $operation = strtoupper($operation);
        $category = $this->stringValue($value('category') ?? $value('regime'));
        $method = $this->stringValue($value('identification_method'));
        $state = strtoupper($this->stringValue($value('member_state_code') ?? $value('country_code')));
        $foreignId = $this->stringValue($value('foreign_insured_id'));
        $personalNumber = $this->stringValue($value('personal_number') ?? $value('bic'));
        $sex = strtoupper($this->stringValue($value('sex')));
        $specialCategory = $this->stringValue($value('special_category'));
        $errors = [];

        if (! in_array($operation, ['N', 'O', 'A'], true)) {
            $errors[] = 'Neznáma operácia dávky; očakáva sa N, O alebo A.';
        }

        if (! isset(self::CHARACTER_BY_CATEGORY[$category])) {
            $errors[] = 'Poistný režim nie je podporovaný pre export.';
        }

        if ($method === '') {
            $method = $category === PatientCoverageCategory::EU->value
                ? PatientIdentificationMethod::FOREIGN_TRIAD->value
                : PatientIdentificationMethod::SLOVAK_IDENTIFIER->value;
        }

        if ($method === PatientIdentificationMethod::SLOVAK_IDENTIFIER->value) {
            if ($personalNumber === '') {
                $errors[] = 'Chýba rodné číslo alebo pridelený BIČ.';
            }

            $state = '';
            $foreignId = '';
            $sex = '';
        } elseif ($method === PatientIdentificationMethod::FOREIGN_TRIAD->value) {
            if ($state === '') {
                $errors[] = 'Chýba štát poistenia.';
            }
            if ($foreignId === '') {
                $errors[] = 'Chýba identifikačné číslo zahraničného poistenca.';
            }
            if (! in_array($sex, ['M', 'F'], true)) {
                $errors[] = 'Pohlavie zahraničného poistenca musí byť M alebo F.';
            }

            $personalNumber = '';
        } else {
            $errors[] = 'Nie je určený použiteľný spôsob identifikácie poistenca.';
        }

        if ($category === PatientCoverageCategory::SPECIAL->value && $specialCategory === '') {
            $errors[] = 'Osobitný režim vyžaduje konkrétnu právnu kategóriu.';
        }

        $character = self::CHARACTER_BY_CATEGORY[$category][$operation] ?? '';

        return new ResolvedInsured(
            character: $character,
            category: $category,
            identificationMethod: $method,
            personalNumber: $personalNumber ?: null,
            memberStateCode: $state ?: null,
            foreignInsuredId: $foreignId ?: null,
            sex: $sex ?: null,
            specialCategory: $specialCategory ?: null,
            errors: array_values(array_unique($errors)),
        );
    }

    public function operationForCharacter(string $character): string
    {
        return match (strtoupper($character)) {
            'N', 'E', 'I' => 'N',
            'O', 'F', 'J' => 'O',
            'A', 'G', 'K' => 'A',
            default => '',
        };
    }

    private function stringValue(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }
}
