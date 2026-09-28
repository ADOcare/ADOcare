<?php

namespace Tests\Unit\Claims;

use App\Services\Claims\InsuredClaimResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\InsuredProfiles;

class InsuredClaimResolverTest extends TestCase
{
    #[DataProvider('supportedProfiles')]
    public function test_profile_is_resolved_for_753d_and_793n(
        string $profileName,
        string $expectedCharacter,
    ): void {
        $profile = InsuredProfiles::all()[$profileName];
        $resolver = new InsuredClaimResolver();

        foreach (['753d', '793n'] as $type) {
            $resolved = $resolver->resolve($profile, 'N');

            self::assertTrue($resolved->isValid(), "{$type}: " . implode(' ', $resolved->errors));
            self::assertSame($expectedCharacter, $resolved->character, $type);

            if ($expectedCharacter === 'E') {
                self::assertNull($resolved->personalNumber, $type);
                self::assertNotNull($resolved->memberStateCode, $type);
                self::assertNotNull($resolved->foreignInsuredId, $type);
                self::assertContains($resolved->sex, ['M', 'F'], $type);
            } else {
                self::assertNull($resolved->memberStateCode, $type);
                self::assertNull($resolved->foreignInsuredId, $type);
                self::assertNull($resolved->sex, $type);
            }
        }
    }

    public static function supportedProfiles(): array
    {
        return [
            ['jana_novotna', 'N'],
            ['martin_kovac', 'N'],
            ['petra_svobodova', 'E'],
            ['anna_mullerova', 'E'],
            ['adam_horvath', 'E'],
            ['milan_petrovic', 'E'],
            ['peter_bielik', 'I'],
            ['eva_polakova', 'I'],
            ['lucia_benesova', 'E'],
        ];
    }

    public function test_incomplete_non_eu_profile_is_blocked(): void
    {
        $resolved = (new InsuredClaimResolver())->resolve(
            InsuredProfiles::all()['oleksandr_melnyk'],
            'N',
        );

        self::assertFalse($resolved->isValid());
        self::assertSame('', $resolved->character);
        self::assertNotEmpty($resolved->errors);
    }

    #[DataProvider('missingForeignFields')]
    public function test_foreign_triad_missing_field_is_blocked(string $field): void
    {
        $profile = InsuredProfiles::all()['petra_svobodova'];
        $profile[$field] = null;
        $resolved = (new InsuredClaimResolver())->resolve($profile, 'N');

        self::assertFalse($resolved->isValid());
    }

    public static function missingForeignFields(): array
    {
        return [['member_state_code'], ['foreign_insured_id'], ['sex']];
    }

    public function test_treaty_state_requires_confirmed_matching_document(): void
    {
        $profile = InsuredProfiles::all()['milan_petrovic'];
        $profile['entitlement_document_type'] = 'EHIC';

        $resolved = (new InsuredClaimResolver())->resolve($profile, 'N');

        self::assertFalse($resolved->isValid());
        self::assertStringContainsString('nárokový doklad', implode(' ', $resolved->errors));
    }

    public function test_entitlement_card_number_is_never_used_as_insured_id(): void
    {
        $profile = InsuredProfiles::all()['petra_svobodova'];
        $resolved = (new InsuredClaimResolver())->resolve($profile, 'N');

        self::assertSame('CZ-TST-0001', $resolved->foreignInsuredId);
        self::assertNotSame($profile['entitlement_document_number'], $resolved->foreignInsuredId);
    }

    public function test_corrective_and_additive_characters_follow_regime(): void
    {
        $resolver = new InsuredClaimResolver();
        $foreign = InsuredProfiles::all()['petra_svobodova'];
        $special = InsuredProfiles::all()['peter_bielik'];

        self::assertSame('F', $resolver->resolve($foreign, 'O')->character);
        self::assertSame('G', $resolver->resolve($foreign, 'A')->character);
        self::assertSame('J', $resolver->resolve($special, 'O')->character);
        self::assertSame('K', $resolver->resolve($special, 'A')->character);
    }

    public function test_foreign_patient_cannot_be_mixed_into_domestic_character(): void
    {
        $resolver = new InsuredClaimResolver();
        $resolved = $resolver->resolve(InsuredProfiles::all()['petra_svobodova'], 'N');

        self::assertSame('E', $resolved->character);
        self::assertNotSame('N', $resolved->character);
    }
}
