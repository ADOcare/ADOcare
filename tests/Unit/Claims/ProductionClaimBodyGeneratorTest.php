<?php

namespace Tests\Unit\Claims;

use App\Http\Controllers\Api\KilometersExportController;
use App\Http\Controllers\Api\PointsExportController;
use App\Services\Claims\ClaimFileGenerator;
use App\Services\Claims\ClaimInterfaceVersionRegistry;
use App\Services\Claims\DelimitedClaimFormatter;
use App\Services\Claims\InsuredClaimResolver;
use App\Services\PointsBatchNumberService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tests\Fixtures\InsuredProfiles;

class ProductionClaimBodyGeneratorTest extends TestCase
{
    #[DataProvider('profiles')]
    public function test_production_generators_place_identification_fields(
        string $profileName,
        string $character,
    ): void {
        $profile = (object) [
            ...InsuredProfiles::all()[$profileName],
            'date' => '2026-09-15',
            'request_date' => '2026-09-01',
            'last_name' => 'TESTOVACI',
            'first_name' => 'PACIENT',
            'diagnosis_code' => 'Z000',
            'procedure_code' => '3439',
            'quantity' => 1,
            'sender_type' => 'O',
            'doctor_pzs' => 'P12345678901',
            'doctor_zpr' => 'A12345678',
            'patient_type' => null,
            'branch_city' => 'BRATISLAVA',
            'branch_address' => 'START 1',
            'patient_city' => 'BRATISLAVA',
            'patient_address' => 'CIEL 2',
        ];

        $pointsFields = $this->invokePrivate(
            $this->pointsController(),
            'build753dAdosBodyFields',
            [$profile, 1, $character],
        );
        $kilometersFields = $this->invokePrivate(
            $this->kilometersController(),
            'build793nAdosBodyFields',
            [$profile, 1, 12.0, 'BA123XY', 1, '25', $character],
        );

        self::assertCount(38, $pointsFields);
        self::assertCount(23, $kilometersFields);

        if ($character === 'E') {
            self::assertSame('', $pointsFields[2]);
            self::assertSame('', $kilometersFields[2]);
            self::assertNotSame('', $pointsFields[19]);
            self::assertNotSame('', $kilometersFields[20]);
            self::assertNotSame('', $pointsFields[20]);
            self::assertNotSame('', $kilometersFields[21]);
            self::assertContains($pointsFields[21], ['M', 'F']);
            self::assertContains($kilometersFields[22], ['M', 'F']);
        } else {
            self::assertNotSame('', $pointsFields[2]);
            self::assertNotSame('', $kilometersFields[2]);
            self::assertSame(['', '', ''], array_slice($pointsFields, 19, 3));
            self::assertSame(['', '', ''], array_slice($kilometersFields, 20, 3));
        }
    }

    public static function profiles(): array
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

    private function pointsController(): PointsExportController
    {
        return new PointsExportController(
            new PointsBatchNumberService(),
            new InsuredClaimResolver(),
            new ClaimInterfaceVersionRegistry(),
            new ClaimFileGenerator(new DelimitedClaimFormatter()),
        );
    }

    private function kilometersController(): KilometersExportController
    {
        return new KilometersExportController(
            new PointsBatchNumberService(),
            new InsuredClaimResolver(),
            new ClaimInterfaceVersionRegistry(),
            new ClaimFileGenerator(new DelimitedClaimFormatter()),
        );
    }

    private function invokePrivate(object $target, string $method, array $arguments): mixed
    {
        $reflection = new ReflectionMethod($target, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($target, $arguments);
    }
}
