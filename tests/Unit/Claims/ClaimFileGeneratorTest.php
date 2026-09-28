<?php

namespace Tests\Unit\Claims;

use App\Services\Claims\ClaimFileGenerator;
use App\Services\Claims\DelimitedClaimFormatter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ClaimFileGeneratorTest extends TestCase
{
    public function test_753d_golden_file_has_exact_fields_and_crlf(): void
    {
        $actual = $this->generator()->generate($this->records753d());

        $expected = str_replace("\n", "\r\n", file_get_contents(__DIR__ . '/golden/753d_foreign.txt'));
        self::assertSame($expected, $actual);
        self::assertDoesNotMatchRegularExpression('/(?<!\r)\n/', $actual);
        $this->assertFieldCounts($actual, [9, 8, 38]);
    }

    public function test_793n_golden_file_has_exact_fields_and_crlf(): void
    {
        $actual = $this->generator()->generate($this->records793n());

        $expected = str_replace("\n", "\r\n", file_get_contents(__DIR__ . '/golden/793n_domestic.txt'));
        self::assertSame($expected, $actual);
        self::assertDoesNotMatchRegularExpression('/(?<!\r)\n/', $actual);
        $this->assertFieldCounts($actual, [9, 7, 23]);
    }

    public function test_wrong_field_count_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->generator()->generate([['fields' => ['N', '753d'], 'count' => 9]]);
    }

    public function test_delimiter_in_value_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->generator()->generate([['fields' => ['A|B'], 'count' => 1]]);
    }

    private function records753d(): array
    {
        return [
            ['fields' => ['E', '753d', '12345678', '20260915', '010925', 1, 1, 1, '0001'], 'count' => 9],
            ['fields' => ['P12345', 'P12345678901', 'A12345678', '1.00', '202609', '850', '', 'EUR'], 'count' => 8],
            ['fields' => [1, '15', '', 'SVOBODOVA PETRA', 'Z000', '1234', 1, '', '', '', '', '', '', '', '', '', 'O', 'P12345678901', 'A12345678', 'CZ', 'CZ-TST-0001', 'F', '20260901', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''], 'count' => 38],
        ];
    }

    private function records793n(): array
    {
        return [
            ['fields' => ['N', '793n', '12345678', '20260915', '010925', 1, 1, 1, '0001'], 'count' => 9],
            ['fields' => ['P12345', 'P12345678901', 'A12345678', '1.00', '202609', '', 'EUR'], 'count' => 7],
            ['fields' => [1, '15', '9951011234', 'NOVOTNA JANA', 'Z000', '', '', 'ADOS', 12, 'BRATISLAVA', 'START 1', 'BRATISLAVA', 'CIEL 2', '012509001', 'BA123XY', 0, '', 'N', 'P12345678901', 'A12345678', '', '', ''], 'count' => 23],
        ];
    }

    private function generator(): ClaimFileGenerator
    {
        return new ClaimFileGenerator(new DelimitedClaimFormatter());
    }

    private function assertFieldCounts(string $content, array $expected): void
    {
        $lines = explode("\r\n", rtrim($content, "\r\n"));

        foreach ($expected as $index => $fieldCount) {
            self::assertSame($fieldCount, substr_count($lines[$index], '|'));
        }
    }
}
