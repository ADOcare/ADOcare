<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Document;
use App\Services\Transport\RouteService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DZCDocumentService
{
    public function __construct(private RouteService $routes)
    {
    }

    public function createDzc(array $data, $actor): array
    {
        $branch = Branch::with('company')->findOrFail((int) $data['branch_id']);
        abort_unless($actor->isInBranch((int) $branch->id), 403);
        $from = CarbonImmutable::parse($data['start'])->toDateString();
        $to = CarbonImmutable::parse($data['end'])->toDateString();
        $carId = isset($data['car_id']) ? (int) $data['car_id'] : null;
        $car = $this->routes->car($actor, $branch, $carId);
        $days = $this->routes->days($actor, $branch, $from, $to, (int) $car->id);
        if ($days === []) {
            throw ValidationException::withMessages(['transport' => ['V období nie sú evidované návštevy. Denný záznam sa nevytvára z náhodných adries.']]);
        }
        $addresses = [];
        $dayTotals = [];
        $legs = [];
        foreach ($days as $day) {
            $date = $day['date'];
            $first = $day['legs'][0];
            $last = $day['legs'][array_key_last($day['legs'])];
            $addresses[$date] = [[
                'type' => 'branch_start', 'address' => $this->address($first['origin']),
                'arrival_time' => $first['departure_time'], 'kilometers' => 0,
            ]];
            foreach ($day['legs'] as $leg) {
                $addresses[$date][] = [
                    'type' => $leg['is_return'] ? 'branch_end' : 'patient',
                    'address' => $this->address($leg['destination']),
                    'arrival_time' => $leg['arrival_time'], 'kilometers' => $leg['distance_m'] / 1000,
                ];
                // Patient linkage stays in the private route cache; DZC contains addresses only.
                unset($leg['patient_ids'], $leg['stop_key']);
                $leg['purpose'] = $leg['is_return']
                    ? 'Návrat na prevádzku po návštevách ADOS'
                    : 'Domáca ošetrovateľská starostlivosť na uvedenej adrese';
                $legs[] = $leg;
            }
            $seconds = CarbonImmutable::parse($first['departure_time'], 'Europe/Bratislava')
                ->diffInSeconds(CarbonImmutable::parse($last['arrival_time'], 'Europe/Bratislava'));
            $dayTotals[$date] = [
                'date' => $date, 'stops' => count($day['legs']) - 1,
                'distance_km' => array_sum(array_column($day['legs'], 'distance_m')) / 1000,
                'total_time' => sprintf('%02d:%02d:%02d', intdiv((int) $seconds, 3600), intdiv((int) $seconds % 3600, 60), (int) $seconds % 60),
            ];
        }
        $payload = [
            'schema_version' => 2, 'user_id' => $actor->id,
            'user_name' => trim($actor->first_name . ' ' . $actor->last_name),
            'company_id' => $branch->company_id, 'company_name' => $branch->company?->name,
            'company_ico' => $branch->company?->ico, 'branch_id' => $branch->id,
            'start_date' => $from, 'end_date' => $to, 'last_journey_date' => $legs[array_key_last($legs)]['service_date'],
            'month' => substr($from, 5, 2), 'year' => substr($from, 0, 4),
            'trip_purpose' => 'Domáca ošetrovateľská starostlivosť',
            'car_id' => $car->id, 'car_model' => $car->model, 'car_vin' => $car->vin,
            'car_license_plate' => $car->evc, 'car_consumption_l_per_100km' => $car->fuel_consumption_l_per_100km,
            'branch_address' => $this->address(['address' => $branch->address, 'city' => $branch->city]),
            'legs' => $legs, 'patient_addresses' => $addresses, 'day_totals' => $dayTotals,
            'month_totals' => ['from' => $from, 'to' => $to, 'distance_km' => array_sum(array_column($legs, 'distance_m')) / 1000],
            'route_fingerprint' => hash('sha256', json_encode([
                $from, $to, $car->id, $car->evc, $car->vin, $legs,
            ], JSON_THROW_ON_ERROR)),
            'calculation_note' => 'Trasa, časy a vzdialenosti sú vypočítané podľa evidovaných návštev.',
            'created_at' => now()->toIso8601String(),
        ];
        return DB::transaction(function () use ($payload, $actor, $branch, $from) {
            DB::table('users')->where('id', $actor->id)->lockForUpdate()->first();
            $period = substr($from, 0, 7);
            $document = Document::query()->where('type', 'dzc')->where('user_id', $actor->id)
                ->where('branch_id', $branch->id)->where('period', $period)->lockForUpdate()->first();
            $attributes = [
                'user_id' => $actor->id, 'branch_id' => $branch->id, 'company_id' => $branch->company_id,
                'type' => 'dzc', 'period' => $period, 'mime_type' => 'application/json',
                'name' => 'dzc_' . $period, 'path' => 'dzcs/' . Str::uuid() . '.json',
            ];
            if ($document) {
                $document->update($attributes);
            } else {
                $document = Document::create($attributes);
            }
            $payload['document_id'] = $document->id;
            if (!Storage::disk('local')->put($document->path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))) {
                throw new \RuntimeException('Denný záznam sa nepodarilo uložiť.');
            }
            return [$document, $payload];
        });
    }

    public function getDzcPayload(Document $document): ?array
    {
        if ($document->type !== 'dzc' || !$document->path || !Storage::disk('local')->exists($document->path)) {
            return null;
        }
        return json_decode(Storage::disk('local')->get($document->path), true, 512, JSON_THROW_ON_ERROR);
    }

    public function createManagerDzcFromVisitLocations(array $data, $actor): array
    {
        $period = CarbonImmutable::createFromFormat('!Y-m', $data['period']);
        return $this->createDzc([
            'branch_id' => $data['branch_id'], 'start' => $period->startOfMonth()->toDateString(),
            'end' => $period->endOfMonth()->toDateString(), 'car_id' => $data['car_id'] ?? null,
        ], $actor);
    }

    private function address(array $address): string
    {
        return trim($address['address'] . ', ' . $address['city'], ', ');
    }
}
