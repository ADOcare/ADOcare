<?php

namespace App\Services\Transport;

use RuntimeException;
use ZipArchive;

/** Minimal OOXML writer: typed data cells, no formulas or external links. */
class XlsxWriter
{
    public function write(array $sheets): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Export XLSX vyžaduje PHP rozšírenie zip.');
        }
        $path = tempnam(sys_get_temp_dir(), 'ados_xlsx_');
        if ($path === false) {
            throw new RuntimeException('Nepodarilo sa vytvoriť dočasný súbor XLSX.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            unlink($path);
            throw new RuntimeException('Nepodarilo sa otvoriť súbor XLSX.');
        }
        try {
            $types = '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
            $workbook = '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
            $relations = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
            $number = 0;
            foreach ($sheets as $name => $rows) {
                $number++;
                $types .= '<Override PartName="/xl/worksheets/sheet' . $number . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
                $workbook .= '<sheet name="' . $this->xml($name) . '" sheetId="' . $number . '" r:id="rId' . $number . '"/>';
                $relations .= '<Relationship Id="rId' . $number . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $number . '.xml"/>';
                $this->add($zip, 'xl/worksheets/sheet' . $number . '.xml', $this->sheet($rows, $name === 'Vozidlo a údaje'));
            }
            $relations .= '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
            $this->add($zip, 'xl/styles.xml', $this->styles());
            $this->add($zip, '[Content_Types].xml', $types . '</Types>');
            $this->add($zip, 'xl/workbook.xml', $workbook . '</sheets></workbook>');
            $this->add($zip, 'xl/_rels/workbook.xml.rels', $relations . '</Relationships>');
            $this->add($zip, '_rels/.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            if (!$zip->close()) {
                throw new RuntimeException('Nepodarilo sa dokončiť XLSX.');
            }
            return $path;
        } catch (\Throwable $error) {
            try {
                $zip->close();
            } catch (\Throwable) {
                // The archive may already be closed.
            }
            @unlink($path);
            throw $error;
        }
    }

    private function sheet(array $rows, bool $metadata = false): string
    {
        $widths = $metadata
            ? '<col min="1" max="1" width="45" customWidth="1"/><col min="2" max="2" width="95" customWidth="1"/>'
            : '<col min="1" max="1" width="10" customWidth="1"/><col min="2" max="13" width="25" customWidth="1"/>';
        $xml = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>' . $widths . '</cols><sheetData>';
        foreach ($rows as $rowIndex => $row) {
            $xml .= '<row r="' . ($rowIndex + 1) . '" ht="' . ($rowIndex === 0 ? 56 : ($metadata ? 48 : 38)) . '" customHeight="1">';
            foreach (array_values($row) as $column => $value) {
                $reference = $this->column($column + 1) . ($rowIndex + 1);
                if (is_int($value) || is_float($value)) {
                    if (!is_finite((float) $value)) {
                        throw new RuntimeException('XLSX obsahuje neplatné číslo.');
                    }
                    $xml .= '<c r="' . $reference . '" s="' . (is_float($value) ? 4 : 0) . '"><v>' . json_encode($value, JSON_THROW_ON_ERROR) . '</v></c>';
                } elseif (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $value)) {
                    $date = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
                    $serial = $date->getTimestamp() / 86400 + 25569;
                    $xml .= '<c r="' . $reference . '" s="' . (strlen($value) > 10 ? 3 : 2) . '"><v>' . json_encode($serial, JSON_THROW_ON_ERROR) . '</v></c>';
                } else {
                    // Explicit string cells prevent spreadsheet formula injection and preserve identifiers.
                    $xml .= '<c r="' . $reference . '" s="' . ($rowIndex === 0 ? 1 : 0) . '" t="inlineStr"><is><t xml:space="preserve">' . $this->xml((string) ($value ?? '')) . '</t></is></c>';
                }
            }
            $xml .= '</row>';
        }
        return $xml . '</sheetData></worksheet>';
    }

    private function styles(): string
    {
        return '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="3"><numFmt numFmtId="164" formatCode="yyyy-mm-dd"/><numFmt numFmtId="165" formatCode="yyyy-mm-dd hh:mm:ss"/><numFmt numFmtId="166" formatCode="0.000"/></numFmts>'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="5">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="166" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }

    private function column(int $number): string
    {
        $result = '';
        while ($number > 0) {
            $number--;
            $result = chr(65 + $number % 26) . $result;
            $number = intdiv($number, 26);
        }
        return $result;
    }

    private function xml(string $value): string
    {
        $value = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value) ?? '';
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function add(ZipArchive $zip, string $name, string $xml): void
    {
        if (!$zip->addFromString($name, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . $xml)) {
            throw new RuntimeException('Nepodarilo sa zapísať časť XLSX.');
        }
    }
}
