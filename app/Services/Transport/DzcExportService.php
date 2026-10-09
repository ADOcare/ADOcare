<?php

namespace App\Services\Transport;

use App\Models\Document;
use App\Models\User;
use App\Services\DZCDocumentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DzcExportService
{
    public function __construct(private DZCDocumentService $documents, private OdometerCalculator $calculator, private XlsxWriter $xlsx)
    {
    }

    public function payload(Document $document): array
    {
        $payload = $this->documents->getDzcPayload($document);
        if (($payload['schema_version'] ?? 0) !== 2 || empty($payload['legs']) || empty($payload['car_id'])) {
            throw ValidationException::withMessages(['document' => ['Pre XLSX najprv znovu vytvorte denný záznam v novom formáte.']]);
        }
        return $payload;
    }

    public function options(Document $document): array
    {
        $payload = $this->payload($document);
        $saved = DB::table('transport_odometer_readings')->where('document_id', $document->id)->first();
        return [
            'car' => $payload['car_license_plate'], 'car_id' => $payload['car_id'],
            'from' => $payload['start_date'], 'to' => $payload['end_date'],
            'reading_date' => $payload['last_journey_date'],
            'calculated_km' => $payload['month_totals']['distance_km'],
            'end_km' => $saved && (int) $saved->car_id === (int) $payload['car_id'] ? (float) $saved->end_km : null,
            'route_changed' => $saved && $saved->route_fingerprint !== $payload['route_fingerprint'],
            'route_fingerprint' => $payload['route_fingerprint'],
            'previous' => $this->previous($payload),
        ];
    }

    public function export(Document $document, User $actor, float $endKm, string $expectedFingerprint): string
    {
        return DB::transaction(function () use ($document, $actor, $endKm, $expectedFingerprint) {
            // Read the currently persisted document after acquiring the lock, not a stale route-bound model.
            $document = Document::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            $payload = $this->payload($document);
            if (!hash_equals($payload['route_fingerprint'], $expectedFingerprint)) {
                throw ValidationException::withMessages(['document' => ['Denný záznam sa zmenil. Otvorte export znova.']]);
            }
            DB::table('cars')->where('id', $payload['car_id'])->lockForUpdate()->first();
            $overlap = DB::table('transport_odometer_readings')->where('company_id', $payload['company_id'])
                ->where('car_id', $payload['car_id'])->where('document_id', '<>', $document->id)
                ->where('period_start', '<=', $payload['last_journey_date'])->where('period_end', '>=', $payload['start_date'])->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['document' => ['Pre toto vozidlo už existuje export iného záznamu s prekrývajúcim sa obdobím. Použite pôvodný záznam vozidla.']]);
            }
            $previous = $this->previous($payload);
            $later = DB::table('transport_odometer_readings')->where('company_id', $payload['company_id'])
                ->where('car_id', $payload['car_id'])->where('period_end', '>', $payload['last_journey_date'])
                ->where('document_id', '<>', $document->id)->orderBy('period_end')->first();
            if ($later && $endKm > (float) $later->end_km) {
                throw ValidationException::withMessages(['end_km' => ['Stav prekračuje už zadaný stav vozidla v neskoršom období.']]);
            }
            try {
                $calculation = $this->calculator->calculate($payload['legs'], $endKm, $previous ? (float) $previous->end_km : null);
            } catch (\InvalidArgumentException $error) {
                throw ValidationException::withMessages(['end_km' => [$error->getMessage()]]);
            }
            $sheets = $this->sheets($payload, $calculation, $previous);
            $path = $this->xlsx->write($sheets);
            try {
                DB::table('transport_odometer_readings')->updateOrInsert(['document_id' => $document->id], [
                    'company_id' => $payload['company_id'], 'car_id' => $payload['car_id'],
                    'recorded_by' => $actor->id, 'period_start' => $payload['start_date'],
                    'period_end' => $payload['last_journey_date'], 'end_km' => $endKm,
                    'route_fingerprint' => $payload['route_fingerprint'], 'created_at' => now(), 'updated_at' => now(),
                ]);
            } catch (\Throwable $error) {
                @unlink($path);
                throw $error;
            }
            return $path;
        });
    }

    private function previous(array $payload): ?object
    {
        return DB::table('transport_odometer_readings')->where('company_id', $payload['company_id'])
            ->where('car_id', $payload['car_id'])->where('period_end', '<', $payload['start_date'])
            ->where('document_id', '<>', $payload['document_id'])->orderByDesc('period_end')->orderByDesc('id')->first();
    }

    public function sheets(array $payload, array $calculation, ?object $previous): array
    {
        $rows = [['Poradie', 'Dátum', 'Vodič', 'EČV', 'Odchod – vypočítaný', 'Príchod – vypočítaný', 'Odkiaľ', 'Kam', 'Účel', 'Km – vypočítané', 'Tachometer začiatok – dopočítaný', 'Tachometer koniec', 'Pôvod koncového stavu']];
        foreach ($calculation['legs'] as $index => $leg) {
            $rows[] = [$index + 1, $leg['service_date'], $payload['user_name'], $payload['car_license_plate'],
                $leg['departure_time'], $leg['arrival_time'],
                $leg['origin']['address'] . ', ' . $leg['origin']['city'],
                $leg['destination']['address'] . ', ' . $leg['destination']['city'],
                $leg['purpose'], $leg['distance_m'] / 1000, $leg['odometer_start_km'], $leg['odometer_end_km'],
                $leg['odometer_end_source'] === 'user_entered' ? 'Zadal používateľ' : 'Dopočítaný'];
        }
        $metadata = [
            ['Údaj', 'Hodnota'], ['Spoločnosť', $payload['company_name']], ['IČO', $payload['company_ico']],
            ['Vozidlo', $payload['car_model']], ['EČV', $payload['car_license_plate']], ['VIN', $payload['car_vin']],
            ['Obdobie od', $payload['start_date']], ['Obdobie do', $payload['end_date']],
            ['Zadaný konečný stav km', $calculation['end_km']], ['Konečný stav patrí k poslednej jazde dňa', $payload['last_journey_date']],
            ['Dopočítaný počiatočný stav km', $calculation['start_km']],
            ['Predchádzajúci zadaný stav km', $previous ? (float) $previous->end_km : null],
            ['Dátum predchádzajúceho údaja', $previous?->period_end],
            ['Rozdiel oproti predchádzajúcemu údaju km', $calculation['difference_km']],
            ['Poznámka', 'Trasa, časy, vzdialenosti a priebežné stavy kilometrov sú vypočítané podľa evidovaných návštev. Koncový stav tachometra zadal používateľ.'],
            ['Rozdiel kilometrov', 'Rozdiel nebol rozpočítaný medzi pacientov. Môže zahŕňať iné jazdy, medzeru medzi obdobiami alebo odchýlku výpočtu.'],
            ['Prevádzkové nákupy', 'Výdavky na prevádzku vozidla sa dokladajú samostatnou účtovnou evidenciou.'],
        ];
        $daily = [['Dátum', 'Počet zastávok', 'Km vrátane návratu – vypočítané', 'Trvanie – vypočítané']];
        foreach ($payload['day_totals'] as $day) {
            $daily[] = [$day['date'], $day['stops'], $day['distance_km'], $day['total_time']];
        }
        return ['Jazdy' => $rows, 'Vozidlo a údaje' => $metadata, 'Denné súčty' => $daily];
    }
}
