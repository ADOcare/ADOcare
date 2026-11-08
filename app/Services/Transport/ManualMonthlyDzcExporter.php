<?php

namespace App\Services\Transport;

use Barryvdh\DomPDF\Facade\Pdf;
use RuntimeException;

/** Renders the existing DZC Blade view for PDF and existing DZC sheets for XLSX. */
class ManualMonthlyDzcExporter
{
    public function __construct(
        private OdometerCalculator $odometer,
        private DzcExportService $existingDzcExport,
        private XlsxWriter $xlsx,
    ) {
    }

    public function export(array $plan, string $format, string $directory, bool $overwrite = false): array
    {
        if (!in_array($format, ['pdf', 'xlsx', 'both'], true)) {
            throw new RuntimeException('Formát musí byť pdf, xlsx alebo both.');
        }

        $filename = 'dzc-navrh-' . $plan['year'] . '-' . $plan['month'];
        $directory = rtrim($directory, DIRECTORY_SEPARATOR);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Nie je možné vytvoriť priečinok: ' . $directory);
        }

        $wanted = $format === 'both' ? ['pdf', 'xlsx'] : [$format];
        // The odometer belongs to the XLSX journey register, not the DZC PDF.
        // Validate before saving either output (avoids a partial --format=both).
        if (in_array('xlsx', $wanted, true) && !isset($plan['odometer_actual_end_km'])) {
            throw new RuntimeException('Pre XLSX zadajte vehicle.odometer_end_km v JSON. PDF tachometer nepotrebuje.');
        }
        foreach ($wanted as $extension) {
            $path = $directory . DIRECTORY_SEPARATOR . $filename . '.' . $extension;
            if (is_file($path) && !$overwrite) {
                throw new RuntimeException('Súbor už existuje: ' . $path . '. Použite --force na jeho nahradenie.');
            }
        }

        $result = [];
        foreach ($wanted as $extension) {
            $path = $directory . DIRECTORY_SEPARATOR . $filename . '.' . $extension;
            $staging = $path . '.pending-' . bin2hex(random_bytes(5));
            $temporaryWorkbook = null;
            try {
                if ($extension === 'pdf') {
                    // Same Blade template and Dompdf setup as DocumentService.
                    // PDF-only does not construct an XLSX and needs no LibreOffice.
                    $bytes = Pdf::loadView('pdf.travel_dzc', $this->pdfViewData($plan))
                        ->setPaper('a4', 'portrait')
                        ->output();
                    if (!is_string($bytes) || !str_starts_with($bytes, '%PDF-')) {
                        throw new RuntimeException('Dompdf nevrátil platný PDF dokument.');
                    }
                    if (file_put_contents($staging, $bytes) === false) {
                        throw new RuntimeException('Nepodarilo sa zapísať PDF.');
                    }
                } else {
                    $temporaryWorkbook = $this->xlsx->write($this->sheets($plan));
                    if (!is_file($temporaryWorkbook) || !copy($temporaryWorkbook, $staging)) {
                        throw new RuntimeException('Nepodarilo sa uložiť XLSX.');
                    }
                }
                if (!is_file($staging) || filesize($staging) < 200 || !rename($staging, $path)) {
                    throw new RuntimeException('Nepodarilo sa dokončiť export: ' . $path);
                }
                $result[$extension] = $path;
            } finally {
                @unlink($staging);
                if ($temporaryWorkbook !== null) {
                    @unlink($temporaryWorkbook);
                }
            }
        }

        return $result;
    }

    /**
     * Match the existing ADOcare DZC Blade PDF exactly: no odometer fields,
     * no special draft sections, and no XLSX calculation on the PDF path.
     * The existing view expects daily totals keyed by date.
     */
    public function pdfViewData(array $plan): array
    {
        $payload = $plan;
        unset($payload['odometer_actual_start_km'], $payload['odometer_actual_end_km'], $payload['odometer_reading_date']);
        $payload['day_totals'] = array_column($plan['day_totals'], null, 'date');

        return [
            'dzcData' => $payload,
            'signatureDataUri' => null,
            'pdfMode' => true,
        ];
    }

    public function sheets(array $plan): array
    {
        $projection = $this->projectOdometer($plan);
        // Same ADOcare XLSX columns and odometer calculation as before.
        $sheets = $this->existingDzcExport->sheets($plan, $projection['calculation'], null);
        $metadata = &$sheets['Vozidlo a údaje'];
        foreach ($metadata as &$row) {
            if (($row[0] ?? null) === 'Konečný stav tachometra') {
                $row[0] = 'Odhadovaný stav tachometra po poslednej plánovanej jazde';
            }
            if (($row[0] ?? null) === 'Konečný stav patrí k poslednej jazde dňa') {
                $row[0] = 'Dátum poslednej plánovanej jazdy';
            }
            if (($row[0] ?? null) === 'Počiatočný stav tachometra') {
                $row[0] = 'Počiatočný stav pre výpočet plánovaných jázd';
            }
        }
        unset($row);
        array_push($metadata,
            ['Typ dokumentu', 'NÁVRH - NEPOTVRDENÉ CESTY, nie evidencia vykonaných jázd'],
            ['Cieľ za mesiac (km)', $plan['month_totals']['target_km']],
            ['Vypočítané cesty (km)', $plan['month_totals']['distance_km']],
            ['Odchýlka od cieľa (km)', $plan['month_totals']['difference_km']],
            ['Preferovaná tolerancia (%)', $plan['month_totals']['tolerance_percent']],
            ['Skutočný koncový tachometer za mesiac', $projection['actual_end']],
            ['Dátum odčítania koncového stavu', $plan['odometer_reading_date']],
            ['Skutočný počiatočný tachometer', $projection['actual_start'] === null ? 'nezadaný' : $projection['actual_start']],
            ['Nevysvetlené kilometre mimo plánovaných jázd', $projection['unallocated'] === null ? 'nemožno určiť bez počiatočného stavu' : $projection['unallocated']],
            ['Poznámka k tachometru', $projection['actual_start'] === null
                ? 'Odhad od konca mesiaca predpokladá, že neboli iné jazdy; hodnoty po úsekoch nie sú potvrdené.'
                : 'Stavy po jednotlivých jazdách sú projekciou od začiatočného stavu, nie potvrdenými odpočtami.'],
            ['Upozornenia', implode(' | ', $plan['warnings']) ?: 'Bez ďalších upozornení'],
        );
        return $sheets;
    }

    private function projectOdometer(array $plan): array
    {
        $roundedLegKm = array_sum(array_map(
            static fn (array $leg): int => (int) round($leg['distance_m'] / 1000),
            $plan['legs']
        ));
        $actualStart = $plan['odometer_actual_start_km'];
        $actualEnd = (int) $plan['odometer_actual_end_km'];
        $projectedEnd = $actualStart !== null ? (int) $actualStart + $roundedLegKm : $actualEnd;
        $result = $this->odometer->calculate($plan['legs'], $projectedEnd);
        $delta = $actualStart === null ? null : $actualEnd - (int) $actualStart;
        $unallocated = $delta === null ? null : $delta - $roundedLegKm;
        if ($unallocated !== null && $unallocated < 0) {
            throw new RuntimeException('Navrhované kilometre prekročili rozdiel tachometra po zaokrúhlení úsekov.');
        }
        return [
            'calculation' => $result,
            'actual_start' => $actualStart,
            'actual_end' => $actualEnd,
            'unallocated' => $unallocated,
        ];
    }
}
