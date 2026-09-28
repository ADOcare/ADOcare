<?php

namespace Tests\Fixtures;

final class InsuredProfiles
{
    public static function all(): array
    {
        return [
            'jana_novotna' => self::profile('domestic', 'slovak_identifier', '9951011234', 'F'),
            'martin_kovac' => self::profile('domestic', 'slovak_identifier', 'BIC-TST-0001', 'M'),
            'petra_svobodova' => self::foreign('CZ', 'CZ-TST-0001', 'F', 'EHIC'),
            'anna_mullerova' => self::foreign('DE', 'DE-TST-0001', 'F', 'PRC', '2026-09-01', '2026-09-30'),
            'adam_horvath' => self::foreign('AT', 'AT-TST-0001', 'M', 'EHIC', personalNumber: '9951019999'),
            'milan_petrovic' => self::foreign('RS', 'RS-TST-0001', 'M', 'SRB/SK 111'),
            'oleksandr_melnyk' => [
                ...self::profile('unclassified', 'incomplete', null, 'M'),
                'category' => 'non_eu',
                'member_state_code' => 'UA',
                'entitlement_confirmed' => false,
            ],
            'peter_bielik' => [
                ...self::profile('special', 'slovak_identifier', 'BIC-TST-0002', 'M'),
                'category' => 'homeless',
                'special_category' => 'homeless',
                'legal_basis' => '§ 9 ods. 4',
                'entitlement_confirmed' => true,
            ],
            'eva_polakova' => [
                ...self::profile('special', 'slovak_identifier', 'BIC-TST-0003', 'F'),
                'category' => 'other',
                'special_category' => 'statutory_entitlement_9_3',
                'other_subtype' => 'Osoba podľa § 9 ods. 3',
                'legal_basis' => '§ 9 ods. 3',
                'entitlement_confirmed' => true,
            ],
            'lucia_benesova' => self::foreign('CZ', 'CZ-TST-0002', 'F', 'temporary_sk_card'),
        ];
    }

    private static function profile(
        string $regime,
        string $method,
        ?string $personalNumber,
        string $sex,
    ): array {
        return [
            'regime' => $regime,
            'identification_method' => $method,
            'personal_number' => $personalNumber,
            'sex' => $sex,
            'member_state_code' => null,
            'foreign_insured_id' => null,
            'special_category' => null,
            'entitlement_document_type' => null,
            'entitlement_document_number' => 'CARD-TST-NEPOUZIT-AKO-ID',
            'entitlement_confirmed' => $regime === 'domestic',
        ];
    }

    private static function foreign(
        string $state,
        string $foreignId,
        string $sex,
        string $documentType,
        ?string $validFrom = null,
        ?string $validTo = null,
        ?string $personalNumber = null,
    ): array {
        return [
            ...self::profile('eu', 'foreign_triad', $personalNumber, $sex),
            'category' => in_array($state, ['RS', 'MK', 'ME'], true) ? 'non_eu' : 'eu',
            'member_state_code' => $state,
            'foreign_insured_id' => $foreignId,
            'entitlement_document_type' => $documentType,
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'entitlement_confirmed' => true,
        ];
    }
}
