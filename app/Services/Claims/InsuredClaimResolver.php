<?php

namespace App\Services\Claims;

use App\Enums\PatientCoverageRegime;
use App\Enums\PatientIdentificationMethod;

class InsuredClaimResolver
{
    private const CHARACTER_BY_REGIME = [
        PatientCoverageRegime::DOMESTIC->value => ['N' => 'N', 'O' => 'O', 'A' => 'A'],
        PatientCoverageRegime::EU->value => ['N' => 'E', 'O' => 'F', 'A' => 'G'],
        PatientCoverageRegime::SPECIAL->value => ['N' => 'I', 'O' => 'J', 'A' => 'K'],
    ];

    private const TREATY_DOCUMENTS = [
        'RS' => ['SRB/SK 111', 'SRB/SK 123'],
        'MK' => ['RM/SK 111', 'RM/SK 112', 'RM/SK 123'],
        'ME' => ['MNE/SK 111', 'MNE/SK 112', 'MNE/SK 123'],
    ];

    public function resolve(object|array $source, string $operation): ResolvedInsured
    {
        $value = fn (string $key) => is_array($source)
            ? ($source[$key] ?? null)
            : ($source->{$key} ?? null);

        $operation = strtoupper($operation);
        $regime = $this->stringValue($value('regime'));
        $method = $this->stringValue($value('identification_method'));
        $state = strtoupper($this->stringValue($value('member_state_code') ?? $value('country_code')));
        $foreignId = $this->stringValue($value('foreign_insured_id'));
        $personalNumber = $this->stringValue($value('personal_number'));
        $sex = strtoupper($this->stringValue($value('sex')));
        $specialCategory = $this->stringValue($value('special_category'));
        $errors = [];
        $entitlementConfirmed = filter_var($value('entitlement_confirmed'), FILTER_VALIDATE_BOOL);

        if (! in_array($operation, ['N', 'O', 'A'], true)) {
            $errors[] = 'Neznáma operácia dávky; očakáva sa N, O alebo A.';
        }

        if (! isset(self::CHARACTER_BY_REGIME[$regime])) {
            $errors[] = 'Poistný režim nie je podporovaný pre export.';
        }

        if ($method === '') {
            $method = $regime === PatientCoverageRegime::DOMESTIC->value
                || $regime === PatientCoverageRegime::SPECIAL->value
                    ? PatientIdentificationMethod::SLOVAK_IDENTIFIER->value
                    : PatientIdentificationMethod::FOREIGN_TRIAD->value;
        }

        if ($regime === PatientCoverageRegime::EU->value && isset(self::TREATY_DOCUMENTS[$state])) {
            $documentType = strtoupper($this->stringValue($value('entitlement_document_type')));
            if (! $entitlementConfirmed || ! in_array($documentType, self::TREATY_DOCUMENTS[$state], true)) {
                $errors[] = 'Zmluvný štát vyžaduje potvrdený príslušný nárokový doklad.';
            }
        }

        if ($regime === PatientCoverageRegime::EU->value && ! $entitlementConfirmed) {
            $errors[] = 'Zahraničný režim vyžaduje potvrdený nárokový doklad.';
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

        if ($regime === PatientCoverageRegime::SPECIAL->value && $specialCategory === '') {
            $errors[] = 'Osobitný režim vyžaduje konkrétnu právnu kategóriu.';
        }

        if ($regime === PatientCoverageRegime::SPECIAL->value && ! $entitlementConfirmed) {
            $errors[] = 'Osobitný režim vyžaduje potvrdený právny nárok.';
        }

        $character = self::CHARACTER_BY_REGIME[$regime][$operation] ?? '';

        return new ResolvedInsured(
            character: $character,
            regime: $regime,
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
