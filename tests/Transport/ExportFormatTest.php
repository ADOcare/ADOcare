<?php

namespace Tests\Transport;

use App\Services\Claims\ClaimFileGenerator;
use App\Services\Claims\ClaimInterfaceVersionRegistry;
use App\Services\Claims\DelimitedClaimFormatter;
use App\Services\Claims\InsuredClaimResolver;
use App\Services\PointsBatchNumberService;
use App\Services\Transport\ClaimExportService;
use App\Services\Transport\ClaimSelectionService;
use App\Services\Transport\XlsxWriter;

class ExportFormatTest extends TransportTestCase
{
    public function test_793n_has_exact_field_counts_ascii_and_stable_journey_number_in_all_regimes(): void
    {
        $service = new ClaimExportService(new PointsBatchNumberService(), new InsuredClaimResolver(),
            new ClaimInterfaceVersionRegistry(), new ClaimFileGenerator(new DelimitedClaimFormatter()),
            $this->createMock(ClaimSelectionService::class));
        $method = new \ReflectionMethod($service, 'build793nAdosContent');
        foreach (['N', 'O', 'A', 'E', 'F', 'G', 'I', 'J', 'K'] as $character) {
            $eu = in_array($character, ['E', 'F', 'G'], true);
            $special = in_array($character, ['I', 'J', 'K'], true);
            $row = (object) [
                'date' => '2026-09-01', 'patient_id' => 1, 'personal_number' => '1234567890',
                'first_name' => 'Žofia', 'last_name' => 'Šťastná', 'diagnosis_code' => 'I10',
                'branch_city' => 'Nitra', 'branch_address' => 'Základňa 1', 'patient_city' => 'Nitra', 'patient_address' => 'Hlavná 1',
                'journey_id' => 51, 'reported_km' => 10, 'doctor_pzs' => 'P12345678901', 'doctor_zpr' => 'A12345678',
                'regime' => $eu ? 'eu' : ($special ? 'special' : 'domestic'),
                'identification_method' => $eu ? 'foreign_triad' : 'slovak_identifier',
                'member_state_code' => 'CZ', 'foreign_insured_id' => 'CZ001', 'sex' => 'F',
                'entitlement_confirmed' => true, 'special_category' => $special ? 'statutory_test_category' : null,
            ];
            $content = $method->invoke($service, [
                'type' => $character, 'batchNumber' => '010925', 'from' => '2026-09-01', 'rows' => collect([$row]),
                'company' => (object) ['ico' => '12345678'], 'branch' => (object) ['identificator' => 'P12345', 'code' => 'P12345678901'],
                'user' => (object) ['code' => 'S12345678'], 'workingTime' => '1.00', 'userId' => 1,
                'insuranceCode' => '25', 'insuranceBranchCode' => '2500', 'userCar' => 'NR123AB',
            ]);
            self::assertSame(0, preg_match('/[^\x00-\x7f]/', $content));
            $lines = explode("\r\n", rtrim($content, "\r\n"));
            self::assertCount(3, $lines);
            foreach ([9, 7, 23] as $index => $count) {
                self::assertStringEndsWith('|', $lines[$index]);
                self::assertCount($count + 1, explode('|', $lines[$index]));
            }
            $body = explode('|', $lines[2]);
            self::assertSame('00000051', $body[13]);
            self::assertSame('10', $body[8]);
            self::assertSame('ADOS', $body[7]);
            self::assertSame('0', $body[15]);
            self::assertSame($eu ? '' : '1234567890', $body[2]);
            self::assertSame($eu ? 'CZ' : '', $body[20]);
            self::assertSame($eu ? 'CZ001' : '', $body[21]);
            self::assertSame($eu ? 'F' : '', $body[22]);
        }
    }

    public function test_xlsx_is_readable_ooxml_with_numeric_dates_and_safe_string_cells(): void
    {
        $path = (new XlsxWriter())->write([
            'Jazdy' => [['Dátum', 'Odkiaľ', 'Km'], ['2026-09-01', '=1+1', 10.25]],
            'Vozidlo a údaje' => [['Údaj', 'Hodnota'], ['IČO', '00123456']],
        ]);
        $zip = new \ZipArchive();
        try {
            self::assertTrue($zip->open($path));
            foreach (['[Content_Types].xml', 'xl/workbook.xml', 'xl/styles.xml', 'xl/worksheets/sheet1.xml', 'xl/worksheets/sheet2.xml'] as $name) {
                self::assertNotFalse(simplexml_load_string($zip->getFromName($name)));
            }
            $xml = new \DOMDocument();
            $xml->loadXML($zip->getFromName('xl/worksheets/sheet1.xml'));
            $xpath = new \DOMXPath($xml);
            $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            self::assertSame('2', $xpath->evaluate('string(//x:c[@r="A2"]/@s)'));
            self::assertSame('10.25', $xpath->evaluate('string(//x:c[@r="C2"]/x:v)'));
            self::assertSame('inlineStr', $xpath->evaluate('string(//x:c[@r="B2"]/@t)'));
            self::assertSame('=1+1', $xpath->evaluate('string(//x:c[@r="B2"]/x:is/x:t)'));
            self::assertSame(0, $xpath->query('//x:f')->length);
            self::assertStringContainsString('00123456', $zip->getFromName('xl/worksheets/sheet2.xml'));
        } finally {
            $zip->close();
            unlink($path);
        }
    }
}
